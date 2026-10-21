<?php
/**
 * bbPress compatibility — purge the pages a reply actually appears on.
 *
 * A bbPress reply (or topic) is its own post, so the generic save_post purge
 * cleared only the reply's own permalink (which just redirects to the topic),
 * the homepage and the author archive. The pages people read the thread on
 * stayed cached until their TTL, so nobody but the poster saw a new reply —
 * the poster only did because logged-in users and guests with bbPress's
 * comment_author_* cookies bypass the cache.
 *
 * This adds, for any reply or topic purge:
 *
 *   - the topic page, with its /page/N/ copies (a new reply lands on the last)
 *   - the topic's forum, its parent forums and the forums index (counts,
 *     "last active", freshness)
 *   - posts that embed the topic through "Post Comments as bbPress Topics"
 *     (bbpress-post-topics), linked by the post meta bbpress_discussion_topic_id
 *
 * It feeds the existing easyopt_purge_post_archive_urls filter, so these ride
 * the same deduplicated shutdown drain (and Cloudflare / host purges) as every
 * other post purge, on every change purge_for_post() handles: a new reply from
 * a logged-in user or a guest, an edit, approve, spam and trash.
 *
 * @package EasyOptimizer
 * @since   2.7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Compat_BBPress {

    /** Most linked posts purged for one topic. */
    const LINKED_POSTS_MAX = 20;

    public static function init() {
        // Registered unconditionally and guarded per call: the filter only runs
        // inside a post purge, long after every plugin has loaded, so plugin
        // load order cannot matter.
        add_filter( 'easyopt_purge_post_archive_urls', array( __CLASS__, 'thread_urls' ), 10, 2 );
    }

    /**
     * @param array $urls    Archive URLs already queued (purged with pagination).
     * @param int   $post_id Post being purged.
     * @return array
     */
    public static function thread_urls( $urls, $post_id ) {
        if ( ! function_exists( 'bbp_get_reply_post_type' ) ) {
            return $urls;
        }

        $type = get_post_type( $post_id );
        if ( bbp_get_reply_post_type() === $type ) {
            $topic_id = (int) bbp_get_reply_topic_id( $post_id );
        } elseif ( bbp_get_topic_post_type() === $type ) {
            $topic_id = (int) $post_id;
        } else {
            return $urls;
        }
        if ( $topic_id <= 0 ) {
            return $urls;
        }

        $urls   = (array) $urls;
        $urls[] = get_permalink( $topic_id );

        $forum_id = (int) bbp_get_topic_forum_id( $topic_id );
        if ( $forum_id > 0 ) {
            $urls[] = get_permalink( $forum_id );
            foreach ( get_post_ancestors( $forum_id ) as $ancestor_id ) {
                $urls[] = get_permalink( $ancestor_id );
            }
        }
        $urls[] = get_post_type_archive_link( bbp_get_forum_post_type() );

        if ( class_exists( 'BBP_PostTopics' ) ) {
            // The same lookup that plugin's own cache.php uses for WP Rocket/W3TC.
            $linked = get_posts( array(
                'post_type'      => apply_filters( 'bbppt_eligible_post_types', array( 'post', 'page' ) ),
                'meta_key'       => 'bbpress_discussion_topic_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_value'     => $topic_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'fields'         => 'ids',
                'posts_per_page' => self::LINKED_POSTS_MAX,
                'no_found_rows'  => true,
            ) );
            foreach ( (array) $linked as $linked_id ) {
                $urls[] = get_permalink( (int) $linked_id );
            }
        }

        return $urls;
    }
}
