<?php
if (!defined('ABSPATH'))
    exit;

class SturdyChat_Indexer
{
    /**
     * Indexes all posts based on the given settings.
     *
     * @param array $s An array of settings controlling the indexing process. This may include:
     *                 - 'index_post_types': An array of post types to index. Defaults to all public types.
     *                 - 'batch_size': The number of posts to process per batch. Defaults to 25.
     * @return array An associative array with the following keys:
     *               - 'ok': A boolean indicating whether the indexing was successful.
     *               - 'message': A string containing a success message with the total counted posts or an error message if it fails.
     */
    public static function indexAll(array $s): array
    {
        $settings = sturdychat_settings_with_defaults($s);

        // fallback to public types
        $configured = isset($settings['index_post_types']) ? (array) $settings['index_post_types'] : [];
        $types = array_values(array_unique(array_merge($configured, sturdychat_all_public_types())));
        $batch = max(1, (int) $settings['batch_size']);

        $args = [
            'post_type' => $types,
            'post_status' => 'publish',
            'posts_per_page' => $batch,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'paged' => 1,
        ];

        $total = 0;
        try {
            do {
                $q = new WP_Query($args);
                $ids = $q->posts;
                if (!$ids)
                    break;
                self::indexPosts($ids, $settings);
                $total += count($ids);
                $args['paged']++;
            } while (count($ids) === $batch);

            return ['ok' => true, 'message' => "Geïndexeerd: $total posts"];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Indexes the specified posts by extracting, processing, and storing their content in chunks.
     *
     * @param array $post_ids An array of post IDs to be indexed.
     * @param array $s An array of settings controlling the indexing process. This may include:
     *                 - 'chunk_chars': The maximum number of characters per content chunk. Defaults to 1200.
     * @return void
     */
    public static function indexPosts(array $post_ids, array $s): void
    {
        global $wpdb;
        $table = STURDYCHAT_TABLE;
        $settings = sturdychat_settings_with_defaults($s);
        $chunk_chars = max(400, (int) $settings['chunk_chars']);

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish')
                continue;

            $content = self::collectContent($post, $settings);
            $content = wp_strip_all_tags(strip_shortcodes(apply_filters('the_content', $content)));
            $hash = hash('sha256', $content);

            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE post_id=%d AND content_hash=%s", $post_id, $hash));
            if ($exists)
                continue;

            $wpdb->delete($table, ['post_id' => $post_id]);

            $chunks = self::chunkText($content, $chunk_chars);
            $now = current_time('mysql');

            foreach ($chunks as $i => $chunk) {
                $vec = SturdyChat_Embedder::embed($chunk, $settings);
                $wpdb->insert($table, [
                    'post_id' => $post_id,
                    'chunk_index' => $i,
                    'content' => $chunk,
                    'embedding' => wp_json_encode($vec),
                    'updated_at' => $now,
                    'content_hash' => $hash,
                ], ['%d', '%d', '%s', '%s', '%s', '%s']);
            }
        }
    }

    /**
     * Collects and compiles content from a given post, including its title, content,
     * taxonomies, and meta information, based on the provided settings.
     *
     * @param WP_Post $post The WordPress post object from which content is collected.
     * @param array $s An array of settings specifying what content to include. This can include:
     *                 - 'include_taxonomies': A boolean indicating whether to include taxonomy terms associated with the post.
     *                 - 'include_meta': A boolean indicating whether to include custom meta fields associated with the post.
     *                 - 'meta_keys': A comma-separated string of meta keys to include in the content, required if 'include_meta' is enabled.
     * @return string A concatenated string of compiled content from the post, formatted and filtered based on the provided settings.
     */
    protected static function collectContent(WP_Post $post, array $s): string
    {
        $settings = sturdychat_settings_with_defaults($s);
        $parts = [get_the_title($post), $post->post_content];

        if (!empty($settings['include_taxonomies'])) {
            $taxes = get_object_taxonomies($post->post_type);
            foreach ($taxes as $tax) {
                $terms = get_the_terms($post, $tax);
                if ($terms && !is_wp_error($terms)) {
                    $parts[] = strtoupper($tax) . ': ' . implode(', ', wp_list_pluck($terms, 'name'));
                }
            }
        }

        if (!empty($settings['include_meta'])) {
            $keys = array_filter(array_map('trim', explode(',', (string) $settings['meta_keys'])));
            foreach ($keys as $k) {
                $v = get_post_meta($post->ID, $k, true);
                if ($v !== '')
                    $parts[] = strtoupper($k) . ': ' . (is_scalar($v) ? $v : wp_json_encode($v));
            }
        }

        // Enrich cpt trough modules
        if (class_exists('SturdyChat_CPTs')) {
            $parts = SturdyChat_CPTs::enrich($post, $parts, $settings);
        }

        return trim(implode("\n\n", array_filter($parts)));
    }

    /**
     * Splits a given text into chunks, ensuring each chunk does not exceed a specified maximum character length,
     * while preserving sentence structure.
     *
     * @param string $text The input text to be chunked.
     * @param int $max_chars The maximum allowable number of characters per chunk.
     * @return array An array of text chunks, each adhering to the maximum character limit.
     */
    protected static function chunkText(string $text, int $max_chars): array
    {
        $text = preg_replace('/\s+/', ' ', (string) $text);
        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [$text];

        $chunks = [];
        $buf = '';
        foreach ($sentences as $s) {
            if (mb_strlen($buf . ' ' . $s) > $max_chars && $buf !== '') {
                $chunks[] = trim($buf);
                $buf = $s;
            } else {
                $buf = $buf ? ($buf . ' ' . $s) : $s;
            }
        }
        if ($buf !== '')
            $chunks[] = trim($buf);
        return $chunks;
    }
}
