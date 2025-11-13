<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class SturdyChat_Admin_SettingsRegister
{
    public static function register(): void
    {
        register_setting('sturdychat_settings_group', 'sturdychat_settings', [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitizeSettings'],
            'default'           => [],
        ]);

        add_settings_section('sturdychat_main', __('General', 'sturdychat-chatbot'), '__return_false', 'sturdychat');

        add_settings_field('provider', __('Provider', 'sturdychat-chatbot'), [__CLASS__, 'fieldProvider'], 'sturdychat', 'sturdychat_main');
        add_settings_field('openai_api_base', __('OpenAI API Base', 'sturdychat-chatbot'), [__CLASS__, 'fieldOpenaiBase'], 'sturdychat', 'sturdychat_main');
        add_settings_field('openai_api_key', __('OpenAI API Key', 'sturdychat-chatbot'), [__CLASS__, 'fieldOpenaiKey'], 'sturdychat', 'sturdychat_main');
        add_settings_field('embed_model', __('Embedding model', 'sturdychat-chatbot'), [__CLASS__, 'fieldEmbedModel'], 'sturdychat', 'sturdychat_main');
        add_settings_field('chat_model', __('Chat model', 'sturdychat-chatbot'), [__CLASS__, 'fieldChatModel'], 'sturdychat', 'sturdychat_main');
        add_settings_field('top_k', __('Top K (context score, DESC % match)', 'sturdychat-chatbot'), [__CLASS__, 'fieldTopK'], 'sturdychat', 'sturdychat_main');
        add_settings_field('temperature', __('Temperature (Weirdness)', 'sturdychat-chatbot'), [__CLASS__, 'fieldTemperature'], 'sturdychat', 'sturdychat_main');

        add_settings_field('index_post_types', __('Post types to index', 'sturdychat-chatbot'), [__CLASS__, 'fieldPostTypes'], 'sturdychat', 'sturdychat_main');
        add_settings_field('include_taxonomies', __('Include taxonomies in context', 'sturdychat-chatbot'), [__CLASS__, 'fieldIncludeTax'], 'sturdychat', 'sturdychat_main');
        add_settings_field('include_meta', __('Include meta fields', 'sturdychat-chatbot'), [__CLASS__, 'fieldIncludeMeta'], 'sturdychat', 'sturdychat_main');
        add_settings_field('meta_keys', __('Meta keys (comma-separated)', 'sturdychat-chatbot'), [__CLASS__, 'fieldMetaKeys'], 'sturdychat', 'sturdychat_main');
        add_settings_field('batch_size', __('Batch size', 'sturdychat-chatbot'), [__CLASS__, 'fieldBatchSize'], 'sturdychat', 'sturdychat_main');
        add_settings_field('chunk_chars', __('Chunk size (characters)', 'sturdychat-chatbot'), [__CLASS__, 'fieldChunkChars'], 'sturdychat', 'sturdychat_main');
        add_settings_field('chat_title', __('Chat title', 'sturdychat-chatbot'), [__CLASS__, 'fieldChatTitle'], 'sturdychat', 'sturdychat_main');
        add_settings_field('fallback_answer', __('Fallback answer', 'sturdychat-chatbot'), [__CLASS__, 'fieldFallbackAnswer'], 'sturdychat', 'sturdychat_main');
        add_settings_field('ui_text_color', __('Answer text color', 'sturdychat-chatbot'), [__CLASS__, 'fieldUiTextColor'], 'sturdychat', 'sturdychat_main');
        add_settings_field('ui_pill_color', __('Source pill color', 'sturdychat-chatbot'), [__CLASS__, 'fieldUiPillColor'], 'sturdychat', 'sturdychat_main');
        add_settings_field('ui_sources_limit', __('Maximum sources to show', 'sturdychat-chatbot'), [__CLASS__, 'fieldUiSourcesLimit'], 'sturdychat', 'sturdychat_main');
        add_settings_field('ui_style_variant', __('Source style', 'sturdychat-chatbot'), [__CLASS__, 'fieldUiStyleVariant'], 'sturdychat', 'sturdychat_main');

        add_settings_field(
            'sitemap_url',
            'Sitemap URL',
            function (): void {
                $s = get_option('sturdychat_settings', []);
                $v = esc_attr($s['sitemap_url'] ?? home_url('/sitemap_index.xml'));
                echo '<input type="text" class="regular-text" name="sturdychat_settings[sitemap_url]" value="' . $v . '" />';
                echo '<p class="description">Root sitemap (e.g., Yoast): usually /sitemap_index.xml</p>';
            },
            'sturdychat',
            'sturdychat_main'
        );
    }

    public static function sanitizeSettings($in): array
    {
        $in  = is_array($in) ? $in : [];
        $out = [];

        $out['provider']        = 'openai';
        $out['openai_api_base'] = isset($in['openai_api_base']) ? esc_url_raw($in['openai_api_base']) : 'https://api.openai.com/v1';
        $out['openai_api_key']  = isset($in['openai_api_key']) ? sanitize_text_field($in['openai_api_key']) : '';
        $out['embed_model']     = !empty($in['embed_model']) ? sanitize_text_field($in['embed_model']) : 'text-embedding-3-small';
        $out['chat_model']      = !empty($in['chat_model']) ? sanitize_text_field($in['chat_model']) : 'gpt-4o-mini';
        $out['top_k']           = max(1, min(12, (int) ($in['top_k'] ?? 6)));
        $out['temperature']     = max(0, min(1, (float) ($in['temperature'] ?? 0.2)));

        $allPublic = sturdychat_all_public_types();

        $orderInput = isset($in['index_post_types_order']) && is_array($in['index_post_types_order'])
            ? array_map(static fn($slug): string => sanitize_key((string) $slug), $in['index_post_types_order'])
            : [];

        $order = [];
        foreach ($orderInput as $slug) {
            if ($slug === '') {
                continue;
            }
            if (!in_array($slug, $order, true) && in_array($slug, $allPublic, true)) {
                $order[] = $slug;
            }
        }

        foreach ($allPublic as $slug) {
            if (!in_array($slug, $order, true)) {
                $order[] = $slug;
            }
        }

        $selectedInput = isset($in['index_post_types']) && is_array($in['index_post_types'])
            ? array_map(static fn($slug): string => sanitize_key((string) $slug), $in['index_post_types'])
            : [];

        $selectedInput = array_values(array_intersect($selectedInput, $allPublic));

        $selected = [];
        foreach ($order as $slug) {
            if (in_array($slug, $selectedInput, true)) {
                $selected[] = $slug;
            }
        }

        if (!$selected) {
            $selected = $allPublic;
        }

        $out['index_post_types']       = $selected;
        $out['index_post_types_order'] = $order;

        $out['include_taxonomies'] = !empty($in['include_taxonomies']) ? 1 : 0;
        $out['include_meta']       = !empty($in['include_meta']) ? 1 : 0;
        $out['meta_keys']          = isset($in['meta_keys']) ? sanitize_text_field($in['meta_keys']) : '';
        $out['batch_size']         = max(1, (int) ($in['batch_size'] ?? 25));
        $out['chunk_chars']        = max(400, min(4000, (int) ($in['chunk_chars'] ?? 1200)));
        $out['chat_title']         = !empty($in['chat_title']) ? sanitize_text_field($in['chat_title']) : 'Stel je vraag';
        $fallbackDefault           = class_exists('SturdyChat_RAG')
            ? SturdyChat_RAG::FALLBACK_ANSWER
            : 'Deze informatie bestaat niet in onze huidige kennisbank. Probeer je vraag specifieker te stellen of gebruik andere trefwoorden.';
        $fallbackInput = isset($in['fallback_answer']) ? sanitize_textarea_field((string) $in['fallback_answer']) : '';
        $out['fallback_answer'] = $fallbackInput !== '' ? $fallbackInput : $fallbackDefault;
        $out['ui_text_color']    = sturdychat_sanitize_hex_color_default($in['ui_text_color'] ?? '', '#0f172a');
        $out['ui_pill_color']    = sturdychat_sanitize_hex_color_default($in['ui_pill_color'] ?? '', '#2563eb');
        $limit                   = isset($in['ui_sources_limit']) ? (int) $in['ui_sources_limit'] : 3;
        $out['ui_sources_limit'] = max(1, min(6, $limit));
        $variant                 = isset($in['ui_style_variant']) ? sanitize_key((string) $in['ui_style_variant']) : 'pill';
        $allowedVariants         = ['pill', 'outline', 'minimal'];
        if (!in_array($variant, $allowedVariants, true)) {
            $variant = 'pill';
        }
        $out['ui_style_variant'] = $variant;

        $out['sitemap_url'] = isset($in['sitemap_url']) ? esc_url_raw($in['sitemap_url']) : home_url('/sitemap_index.xml');

        return $out;
    }

    public static function fieldProvider(): void
    {
        echo '<strong>OpenAI</strong> (MVP).';
    }

    public static function fieldOpenaiBase(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf(
            '<input type="url" name="sturdychat_settings[openai_api_base]" value="%s" class="regular-text" placeholder="https://api.openai.com/v1" />',
            esc_attr($s['openai_api_base'] ?? '')
        );
    }

    public static function fieldOpenaiKey(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf(
            '<input type="password" name="sturdychat_settings[openai_api_key]" value="%s" class="regular-text" />',
            esc_attr($s['openai_api_key'] ?? '')
        );
        echo '<p class="description">Server-side only.</p>';
    }

    public static function fieldEmbedModel(): void
    {
        $s   = get_option('sturdychat_settings', []);
        $val = $s['embed_model'] ?? 'text-embedding-3-small';
        printf('<input type="text" name="sturdychat_settings[embed_model]" value="%s" class="regular-text" />', esc_attr($val));
    }

    public static function fieldChatModel(): void
    {
        $s   = get_option('sturdychat_settings', []);
        $val = $s['chat_model'] ?? 'gpt-4o-mini';
        printf('<input type="text" name="sturdychat_settings[chat_model]" value="%s" class="regular-text" />', esc_attr($val));
    }

    public static function fieldTopK(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf('<input type="number" min="1" max="12" name="sturdychat_settings[top_k]" value="%d" class="small-text" />', (int) ($s['top_k'] ?? 6));
    }

    public static function fieldTemperature(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf(
            '<input type="number" step="0.1" min="0" max="1" name="sturdychat_settings[temperature]" value="%s" class="small-text" />',
            esc_attr((string) ($s['temperature'] ?? 0.2))
        );
    }

    public static function fieldPostTypes(): void
    {
        $s    = get_option('sturdychat_settings', []);
        $objs = get_post_types(['public' => true], 'objects');
        unset($objs['attachment']);

        $available = array_keys($objs);

        $orderStored = isset($s['index_post_types_order']) && is_array($s['index_post_types_order'])
            ? array_values(array_intersect($s['index_post_types_order'], $available))
            : [];

        $order = array_merge($orderStored, array_diff($available, $orderStored));

        $selected = isset($s['index_post_types']) && is_array($s['index_post_types']) && $s['index_post_types']
            ? array_values(array_intersect($s['index_post_types'], $order))
            : $order;

        static $assetsInjected = false;
        if (!$assetsInjected) {
            $assetsInjected = true;
            ?>
            <style>
                .sturdychat-cpt-sortable{margin:0;padding:0;list-style:none;max-width:520px;}
                .sturdychat-cpt-row{display:flex;align-items:center;gap:0.6rem;padding:0.45rem 0.6rem;border:1px solid #ccd0d4;border-radius:4px;background:#fff;margin-bottom:0.5rem;cursor:move;transition:background 0.2s ease;}
                .sturdychat-cpt-row.is-unchecked{opacity:0.55;}
                .sturdychat-cpt-row.is-dragging{opacity:0.4;background:#f6f7f7;}
                .sturdychat-drag-handle{cursor:grab;font-size:16px;color:#646970;display:inline-flex;align-items:center;}
                .sturdychat-cpt-row input[type="checkbox"]{margin-right:0.35rem;}
                .sturdychat-cpt-row:focus-within{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;}
            </style>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    document.querySelectorAll('.sturdychat-cpt-sortable').forEach(function (list) {
                        var dragged = null;

                        var updateState = function () {
                            list.querySelectorAll('.sturdychat-cpt-row').forEach(function (row) {
                                var checkbox = row.querySelector('input[type="checkbox"]');
                                if (!checkbox) {
                                    return;
                                }
                                row.classList.toggle('is-unchecked', !checkbox.checked);
                            });
                        };

                        list.querySelectorAll('.sturdychat-cpt-row').forEach(function (row) {
                            row.addEventListener('dragstart', function (e) {
                                dragged = row;
                                row.classList.add('is-dragging');
                                if (e.dataTransfer) {
                                    e.dataTransfer.effectAllowed = 'move';
                                    try {
                                        e.dataTransfer.setData('text/plain', '');
                                    } catch (err) {}
                                }
                            });
                            row.addEventListener('dragend', function () {
                                if (dragged) {
                                    dragged.classList.remove('is-dragging');
                                }
                                dragged = null;
                            });
                        });

                        list.addEventListener('dragover', function (e) {
                            if (!dragged) {
                                return;
                            }
                            e.preventDefault();
                            var target = e.target.closest('.sturdychat-cpt-row');
                            if (!target || target === dragged) {
                                return;
                            }
                            var rect = target.getBoundingClientRect();
                            var after = e.clientY > rect.top + rect.height / 2;
                            if (after) {
                                target.after(dragged);
                            } else {
                                target.before(dragged);
                            }
                        });

                        list.addEventListener('drop', function (e) {
                            if (dragged) {
                                e.preventDefault();
                                dragged.classList.remove('is-dragging');
                                dragged = null;
                            }
                        });

                        list.addEventListener('change', function (e) {
                            if (e.target.matches('input[type="checkbox"]')) {
                                updateState();
                            }
                        });

                        updateState();
                    });
                });
            </script>
            <?php
        }

        echo '<ul class="sturdychat-cpt-sortable" data-sturdychat-sortable>';
        foreach ($order as $slug) {
            if (!isset($objs[$slug])) {
                continue;
            }
            $label     = $objs[$slug]->labels->name ?? $slug;
            $isChecked = in_array($slug, $selected, true);

            echo '<li class="sturdychat-cpt-row' . ($isChecked ? '' : ' is-unchecked') . '" draggable="true" data-cpt="' . esc_attr($slug) . '">';
            echo '<span class="sturdychat-drag-handle" aria-hidden="true">&#9776;</span>';
            echo '<label><input type="checkbox" name="sturdychat_settings[index_post_types][]" value="' . esc_attr($slug) . '" ' . checked($isChecked, true, false) . '> ' . esc_html($label) . '</label>';
            echo '<input type="hidden" name="sturdychat_settings[index_post_types_order][]" value="' . esc_attr($slug) . '">';
            echo '</li>';
        }
        echo '</ul>';
        echo '<p class="description">' . esc_html__('Sleep om de prioriteit van CPT\'s te bepalen. Hogere items krijgen een kleine boost bij relevante resultaten.', 'sturdychat-chatbot') . '</p>';
    }

    public static function fieldIncludeTax(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf(
            '<label><input type="checkbox" name="sturdychat_settings[include_taxonomies]" value="1" %s> %s</label>',
            checked(!empty($s['include_taxonomies']), true, false),
            __('Send terms (categories, tags, custom taxonomies)', 'sturdychat-chatbot')
        );
    }

    public static function fieldIncludeMeta(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf(
            '<label><input type="checkbox" name="sturdychat_settings[include_meta]" value="1" %s> %s</label>',
            checked(!empty($s['include_meta']), true, false),
            __('Send selected meta fields', 'sturdychat-chatbot')
        );
    }

    public static function fieldMetaKeys(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf(
            '<input type="text" name="sturdychat_settings[meta_keys]" value="%s" class="regular-text" placeholder="key1, key2" />',
            esc_attr($s['meta_keys'] ?? '')
        );
    }

    public static function fieldBatchSize(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf('<input type="number" min="1" max="500" name="sturdychat_settings[batch_size]" value="%d" class="small-text" />', (int) ($s['batch_size'] ?? 25));
    }

    public static function fieldChunkChars(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf('<input type="number" min="400" max="4000" name="sturdychat_settings[chunk_chars]" value="%d" class="small-text" />', (int) ($s['chunk_chars'] ?? 1200));
    }

    public static function fieldChatTitle(): void
    {
        $s = get_option('sturdychat_settings', []);
        printf('<input type="text" name="sturdychat_settings[chat_title]" value="%s" class="regular-text" />', esc_attr($s['chat_title'] ?? 'Stel je vraag'));
    }

    public static function fieldFallbackAnswer(): void
    {
        $s = get_option('sturdychat_settings', []);
        $default = class_exists('SturdyChat_RAG') ? SturdyChat_RAG::FALLBACK_ANSWER : '';
        $value = isset($s['fallback_answer']) && $s['fallback_answer'] !== '' ? (string) $s['fallback_answer'] : $default;
        echo '<textarea name="sturdychat_settings[fallback_answer]" rows="3" class="large-text code">' . esc_textarea($value) . '</textarea>';
        echo '<p class="description">' . esc_html__('Dit antwoord tonen we wanneer er geen context gevonden wordt.', 'sturdychat-chatbot') . '</p>';
    }

    public static function fieldUiTextColor(): void
    {
        $s = get_option('sturdychat_settings', []);
        $val = $s['ui_text_color'] ?? '#0f172a';
        printf('<input type="color" name="sturdychat_settings[ui_text_color]" value="%s" />', esc_attr($val));
        echo '<p class="description">' . esc_html__('Pas hiermee de kleur van het antwoord aan.', 'sturdychat-chatbot') . '</p>';
    }

    public static function fieldUiPillColor(): void
    {
        $s = get_option('sturdychat_settings', []);
        $val = $s['ui_pill_color'] ?? '#2563eb';
        printf('<input type="color" name="sturdychat_settings[ui_pill_color]" value="%s" />', esc_attr($val));
        echo '<p class="description">' . esc_html__('Bepaalt de achtergrondkleur van de bron-pills/tabs.', 'sturdychat-chatbot') . '</p>';
    }

    public static function fieldUiSourcesLimit(): void
    {
        $s = get_option('sturdychat_settings', []);
        $val = (int) ($s['ui_sources_limit'] ?? 3);
        printf('<input type="number" min="1" max="6" name="sturdychat_settings[ui_sources_limit]" value="%d" />', $val);
        echo '<p class="description">' . esc_html__('Kies hoeveel bronnen maximaal worden getoond (max 6).', 'sturdychat-chatbot') . '</p>';
    }

    public static function fieldUiStyleVariant(): void
    {
        $s = get_option('sturdychat_settings', []);
        $val = $s['ui_style_variant'] ?? 'pill';
        $options = [
            'pill'    => __('Gevulde pills', 'sturdychat-chatbot'),
            'outline' => __('Outline tabs', 'sturdychat-chatbot'),
            'minimal' => __('Minimal underline', 'sturdychat-chatbot'),
        ];

        echo '<select name="sturdychat_settings[ui_style_variant]">';
        foreach ($options as $key => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($key),
                selected($val, $key, false),
                esc_html($label)
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Bepaal de stijl van de bronnenlijst.', 'sturdychat-chatbot') . '</p>';
    }
}
