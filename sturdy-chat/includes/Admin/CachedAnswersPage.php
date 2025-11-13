<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class SturdyChat_Admin_CachedAnswersPage
{
    /**
     * Render the cached answers management UI and handle POST actions.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        global $wpdb;

        $errors  = [];
        $updated = false;

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['sturdychat_cache_action'])) {
            $postAction = sanitize_key(wp_unslash((string) $_POST['sturdychat_cache_action']));
            if ($postAction === 'delete') {
                check_admin_referer('sturdychat_cache_delete');

                $entryId = isset($_POST['entry_id']) ? (int) wp_unslash((string) $_POST['entry_id']) : 0;
                if ($entryId <= 0) {
                    $errors[] = __('Entry not found.', 'sturdychat-chatbot');
                }

                if (!$errors) {
                    $deleted = $wpdb->delete(STURDYCHAT_TABLE_CACHE, ['id' => $entryId], ['%d']);
                    if (false === $deleted) {
                        $errors[] = __('Database error while deleting the cached answer.', 'sturdychat-chatbot');
                    } else {
                        $redirect = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash((string) $_POST['redirect_to'])) : '';
                        if (!$redirect) {
                            $redirect = add_query_arg(
                                [
                                    'page'     => 'sturdychat-cached-answers',
                                    'deleted'  => 1,
                                ],
                                admin_url('admin.php')
                            );
                        } else {
                            $redirect = add_query_arg('deleted', 1, remove_query_arg('deleted', $redirect));
                        }

                        wp_safe_redirect($redirect);
                        exit;
                    }
                }
            } elseif ($postAction === 'bulk_delete') {
                check_admin_referer('sturdychat_cache_bulk_delete');

                $ids = isset($_POST['sturdychat_bulk']) ? (array) wp_unslash($_POST['sturdychat_bulk']) : [];
                $ids = array_values(array_filter(array_map('intval', $ids), static function (int $id): bool {
                    return $id > 0;
                }));

                if (!$ids) {
                    $errors[] = __('Select at least one cached answer to delete.', 'sturdychat-chatbot');
                }

                if (!$errors) {
                    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                    $sql = 'DELETE FROM ' . STURDYCHAT_TABLE_CACHE . ' WHERE id IN (' . $placeholders . ')';

                    $prepared = call_user_func_array([$wpdb, 'prepare'], array_merge([$sql], $ids));
                    if (!$prepared) {
                        $errors[] = __('Unable to prepare bulk delete statement.', 'sturdychat-chatbot');
                    }

                    if (!$errors) {
                        $deleted = $wpdb->query($prepared);

                        if (false === $deleted) {
                            $errors[] = __('Database error while deleting cached answers.', 'sturdychat-chatbot');
                        } else {
                            $redirect = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash((string) $_POST['redirect_to'])) : '';
                            if (!$redirect) {
                                $redirect = add_query_arg(
                                    [
                                        'page'         => 'sturdychat-cached-answers',
                                        'bulkdeleted'  => count($ids),
                                    ],
                                    admin_url('admin.php')
                                );
                            } else {
                                $redirect = add_query_arg('bulkdeleted', count($ids), remove_query_arg('bulkdeleted', $redirect));
                            }

                            wp_safe_redirect($redirect);
                            exit;
                        }
                    }
                }
            } elseif ($postAction === 'update') {
                check_admin_referer('sturdychat_cache_edit');

                $entryId = isset($_POST['entry_id']) ? (int) wp_unslash((string) $_POST['entry_id']) : 0;
                $question = isset($_POST['question']) ? sanitize_text_field(wp_unslash((string) $_POST['question'])) : '';
                $answer = isset($_POST['answer']) ? wp_kses_post(wp_unslash((string) $_POST['answer'])) : '';
                $sourcesRaw = isset($_POST['sources']) ? wp_unslash((string) $_POST['sources']) : '';
                $redirectTo = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash((string) $_POST['redirect_to'])) : '';

                if ($entryId <= 0) {
                    $errors[] = __('Entry not found.', 'sturdychat-chatbot');
                }

                if ($question === '') {
                    $errors[] = __('Question is required.', 'sturdychat-chatbot');
                }

                if ($answer === '') {
                    $errors[] = __('Answer is required.', 'sturdychat-chatbot');
                }

                $normalized = SturdyChat_Cache::normalizeForStorage($question);
                if ($normalized['normalized'] === '') {
                    $errors[] = __('Unable to normalize question.', 'sturdychat-chatbot');
                }

                $sources = SturdyChat_Cache::sanitizeSources($sourcesRaw);
                if (!$sources['ok']) {
                    $errors[] = $sources['message'] ?? __('Invalid sources payload', 'sturdychat-chatbot');
                }

                if (!$errors) {
                    $updateData = [
                        'question'            => $question,
                        'answer'              => $answer,
                        'sources'             => $sources['value'],
                        'normalized_question' => $normalized['normalized'],
                        'normalized_hash'     => $normalized['hash'],
                    ];

                    $updated = $wpdb->update(
                        STURDYCHAT_TABLE_CACHE,
                        $updateData,
                        ['id' => $entryId],
                        ['%s', '%s', '%s', '%s', '%s'],
                        ['%d']
                    );

                    if (false === $updated) {
                        $errors[] = __('Failed to update cached answer.', 'sturdychat-chatbot');
                    } else {
                        if ($redirectTo) {
                            $redirectTo = add_query_arg('updated', 1, remove_query_arg('updated', $redirectTo));
                        } else {
                            $redirectTo = add_query_arg(
                                [
                                    'page'    => 'sturdychat-cached-answers',
                                    'updated' => 1,
                                ],
                                admin_url('admin.php')
                            );
                        }

                        wp_safe_redirect($redirectTo);
                        exit;
                    }
                }
            }
        }

        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
        $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $perPage = 20;
        $offset = ($paged - 1) * $perPage;

        $action = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : '';

        $whereClause = '';
        $whereParams = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $whereClause = 'WHERE question LIKE %s OR normalized_question LIKE %s OR answer LIKE %s';
            $whereParams = [$like, $like, $like];
        }

        $countSql = 'SELECT COUNT(*) FROM ' . STURDYCHAT_TABLE_CACHE . ' ' . $whereClause;
        $total = $whereClause ? (int) $wpdb->get_var($wpdb->prepare($countSql, $whereParams)) : (int) $wpdb->get_var($countSql);

        $selectSql = 'SELECT id, question, answer, sources, created_at FROM ' . STURDYCHAT_TABLE_CACHE . ' ' . $whereClause . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $rows = $whereClause
            ? $wpdb->get_results($wpdb->prepare($selectSql, array_merge($whereParams, [$perPage, $offset])), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($selectSql, $perPage, $offset), ARRAY_A);

        $rows = is_array($rows) ? $rows : [];

        $currentEditId = isset($_GET['entry']) ? (int) $_GET['entry'] : 0;
        $entryToEdit = null;
        if ($action === 'edit' && $currentEditId > 0) {
            $entryToEdit = $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM ' . STURDYCHAT_TABLE_CACHE . ' WHERE id = %d', $currentEditId),
                ARRAY_A
            );
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Cached Answers', 'sturdychat-chatbot') . '</h1>';

        if (!empty($_GET['updated']) || $updated) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cached answer updated.', 'sturdychat-chatbot') . '</p></div>';
        }

        if (!empty($_GET['deleted'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cached answer deleted.', 'sturdychat-chatbot') . '</p></div>';
        }

        if (!empty($_GET['bulkdeleted'])) {
            $count = (int) $_GET['bulkdeleted'];
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(_n('%d cached answer deleted.', '%d cached answers deleted.', $count, 'sturdychat-chatbot'), $count)) . '</p></div>';
        }

        foreach ($errors as $message) {
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        }

        echo '<form method="get" class="search-form">';
        echo '<input type="hidden" name="page" value="sturdychat-cached-answers" />';
        echo '<p class="search-box">';
        echo '<label class="screen-reader-text" for="sturdychat-cache-search">' . esc_html__('Search cached answers', 'sturdychat-chatbot') . '</label>';
        echo '<input type="search" id="sturdychat-cache-search" name="s" value="' . esc_attr($search) . '" />';
        echo '<input type="submit" class="button" value="' . esc_attr__('Search', 'sturdychat-chatbot') . '" />';
        echo '</p>';
        echo '</form>';

        if ($entryToEdit) {
            $redirectArgs = [
                'page'   => 'sturdychat-cached-answers',
                'action' => 'edit',
                'entry'  => $entryToEdit['id'],
                'paged'  => $paged,
            ];
            if ($search !== '') {
                $redirectArgs['s'] = $search;
            }

            $redirectTo = add_query_arg($redirectArgs, admin_url('admin.php'));

            $listRedirectArgs = [
                'page'  => 'sturdychat-cached-answers',
                'paged' => $paged,
            ];
            if ($search !== '') {
                $listRedirectArgs['s'] = $search;
            }

            $redirectToList = add_query_arg($listRedirectArgs, admin_url('admin.php'));

            $sourcesForEditor = SturdyChat_Cache::formatSourcesForEditor((string) ($entryToEdit['sources'] ?? ''));

            echo '<div class="card" style="max-width:960px; margin-top: 20px;">';
            echo '<h2>' . esc_html__('Edit cached answer', 'sturdychat-chatbot') . '</h2>';
            echo '<form method="post">';
            wp_nonce_field('sturdychat_cache_edit');
            echo '<input type="hidden" name="sturdychat_cache_action" value="update" />';
            echo '<input type="hidden" name="entry_id" value="' . (int) $entryToEdit['id'] . '" />';
            echo '<input type="hidden" name="redirect_to" value="' . esc_attr($redirectTo) . '" />';

            echo '<p><label>' . esc_html__('Question', 'sturdychat-chatbot') . '<br />';
            echo '<input type="text" name="question" value="' . esc_attr($entryToEdit['question'] ?? '') . '" class="regular-text" /></label></p>';

            echo '<p><label>' . esc_html__('Answer', 'sturdychat-chatbot') . '<br />';
            echo '<textarea name="answer" rows="6" class="large-text" style="width:100%">' . esc_textarea($entryToEdit['answer'] ?? '') . '</textarea></label></p>';

            echo '<p><label>' . esc_html__('Sources (JSON array)', 'sturdychat-chatbot') . '<br />';
            echo '<textarea name="sources" rows="6" class="large-text" style="width:100%" placeholder="[\n  {\n    \"title\": \"...\",\n    \"url\": \"https://example.com\"\n  }\n]">' . esc_textarea($sourcesForEditor) . '</textarea></label></p>';

            echo '<p style="display:flex;gap:10px;flex-wrap:wrap;">';
            echo '<button type="submit" class="button button-primary">' . esc_html__('Save', 'sturdychat-chatbot') . '</button>';
            echo '<a href="' . esc_url($redirectToList) . '" class="button button-link">' . esc_html__('Back to list', 'sturdychat-chatbot') . '</a>';
            echo '</p>';

            echo '</form>';
            echo '</div>';
        }

        $redirectBase = [
            'page'  => 'sturdychat-cached-answers',
            'paged' => $paged,
        ];
        if ($search !== '') {
            $redirectBase['s'] = $search;
        }
        $redirectHidden = esc_attr(add_query_arg($redirectBase, admin_url('admin.php')));

        echo '<form method="post">';
        wp_nonce_field('sturdychat_cache_bulk_delete');
        echo '<input type="hidden" name="sturdychat_cache_action" value="bulk_delete" />';
        echo '<input type="hidden" name="redirect_to" value="' . $redirectHidden . '" />';

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<td id="cb" class="manage-column column-cb check-column"><input type="checkbox" onclick="jQuery(\'.sturdychat-bulk-cb\').prop(\'checked\', this.checked);" /></td>';
        echo '<th>' . esc_html__('Question', 'sturdychat-chatbot') . '</th>';
        echo '<th>' . esc_html__('Answer preview', 'sturdychat-chatbot') . '</th>';
        echo '<th>' . esc_html__('Created', 'sturdychat-chatbot') . '</th>';
        echo '<th>' . esc_html__('Actions', 'sturdychat-chatbot') . '</th>';
        echo '</tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="5">' . esc_html__('No cached answers found.', 'sturdychat-chatbot') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                $editUrl = add_query_arg(
                    [
                        'page'   => 'sturdychat-cached-answers',
                        'action' => 'edit',
                        'entry'  => $row['id'],
                        'paged'  => $paged,
                        's'      => $search,
                    ],
                    admin_url('admin.php')
                );

                echo '<tr>';
                echo '<th scope="row" class="check-column"><input type="checkbox" class="sturdychat-bulk-cb" name="sturdychat_bulk[]" value="' . (int) $row['id'] . '" /></th>';
                echo '<td><strong>' . esc_html($row['question']) . '</strong></td>';
                echo '<td>' . esc_html(wp_trim_words(wp_strip_all_tags($row['answer']), 20, '…')) . '</td>';
                echo '<td>' . esc_html(mysql2date('Y-m-d H:i', $row['created_at'])) . '</td>';
                echo '<td>';

                echo '<a class="button button-small" href="' . esc_url($editUrl) . '">' . esc_html__('Edit', 'sturdychat-chatbot') . '</a> ';
                echo '<form method="post" style="display:inline;margin:0;">';
                wp_nonce_field('sturdychat_cache_delete');
                echo '<input type="hidden" name="sturdychat_cache_action" value="delete" />';
                echo '<input type="hidden" name="entry_id" value="' . (int) $row['id'] . '" />';
                echo '<input type="hidden" name="redirect_to" value="' . $redirectHidden . '" />';
                $confirm = esc_js(__('Delete this cached answer?', 'sturdychat-chatbot'));
                echo '<button type="submit" class="button button-small button-danger" onclick="return confirm(\'' . $confirm . '\');">' . esc_html__('Delete', 'sturdychat-chatbot') . '</button>';
                echo '</form>';

                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        echo '<div style="margin-top:10px;">';
        echo '<button type="submit" class="button button-secondary" onclick="return confirm(\'' . esc_js(__('Delete selected cached answers?', 'sturdychat-chatbot')) . '\');">' . esc_html__('Delete selected', 'sturdychat-chatbot') . '</button>';
        echo '</div>';

        echo '</form>';

        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($totalPages > 1) {
            $baseUrl = add_query_arg(
                [
                    'page'  => 'sturdychat-cached-answers',
                    'paged' => '%#%',
                ],
                admin_url('admin.php')
            );

            $addArgs = [];
            if ($search !== '') {
                $addArgs['s'] = $search;
            }

            $pagination = paginate_links([
                'base'      => $baseUrl,
                'format'    => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total'     => $totalPages,
                'current'   => $paged,
                'add_args'  => $addArgs,
            ]);

            if ($pagination) {
                echo '<div class="tablenav"><div class="tablenav-pages">' . $pagination . '</div></div>';
            }
        }

        echo '</div>';
    }
}
