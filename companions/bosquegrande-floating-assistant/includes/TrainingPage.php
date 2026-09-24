<?php

declare(strict_types=1);

namespace BGCompanion\Assistant;

if (!defined('ABSPATH')) exit;

/**
 * BosqueGrande > Assistant Training: where the webmaster adds the
 * organization's own reference material.
 */
final class TrainingPage
{
    public const SLUG = 'bgca-training';

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'menu'], 61);
        foreach (['add', 'update', 'toggle', 'delete'] as $action) {
            add_action('admin_post_bgca_training_' . $action, [$this, $action]);
        }
    }

    public static function url(array $args = []): string
    {
        $base = admin_url((Plugin::coreActive() ? 'admin.php' : 'options-general.php') . '?page=' . self::SLUG);
        return $args ? add_query_arg($args, $base) : $base;
    }

    public function menu(): void
    {
        $parent = Plugin::coreActive() ? 'bosquegrande' : 'options-general.php';
        add_submenu_page($parent, 'Assistant Training', 'Assistant Training', 'manage_options', self::SLUG, [$this, 'render']);
    }

    /* ── Screens ──────────────────────────────────────────────── */

    public function render(): void
    {
        if (!current_user_can('manage_options')) return;
        Training::maybeInstall();
        $editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
        echo '<div class="wrap bgca-training"><h1>Assistant Training</h1>';
        $this->notices();
        if ($editId > 0) {
            $this->renderEdit($editId);
        } else {
            $this->renderList();
        }
        echo '</div>';
    }

    private function notices(): void
    {
        $notice = get_transient('bgca_notice_' . get_current_user_id());
        if (!is_array($notice)) return;
        delete_transient('bgca_notice_' . get_current_user_id());
        foreach ($notice as [$type, $message]) {
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . wp_kses($message, ['strong' => [], 'code' => []]) . '</p></div>';
        }
    }

    private function renderList(): void
    {
        $status = Plugin::status();
        $entries = Training::all();
        $total = Training::totalChars();

        echo '<p>Teach the assistant about your organization: frequently asked questions, hours and locations, programs, prices, policies, '
            . 'how to register or volunteer. When someone asks a question, the passages that match it are sent along with the question, '
            . 'so the answer comes from your own material instead of a guess.</p>';
        echo '<ul class="ul-disc">'
            . '<li><strong>Visitors and staff</strong>: used by the Floating Assistant on your public pages and by the staff Assistant in the dashboard.</li>'
            . '<li><strong>Staff only</strong>: used only by the staff Assistant (BosqueGrande &gt; Assistant). Visitors never get it.</li>'
            . '<li><strong>Always include</strong>: for a short entry every answer should know, such as your hours and contact details. Its first '
            . number_format_i18n(Training::PINNED_BUDGET) . ' characters go with every question for that audience.</li></ul>';
        if ($status['ready']) {
            echo '<p>Answers use your own <strong>' . esc_html(Plugin::providerLabel($status['provider'])) . '</strong> key from '
                . '<a href="' . esc_url(Plugin::toolsUrl()) . '">Assisted Tools</a>. Only the matching passages are sent, never the whole library.</p>';
        } else {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($status['reason']) . ' You can add training info now; '
                . 'the assistant will use it once your service provider key is in place'
                . (Plugin::coreActive() ? ' (<a href="' . esc_url(Plugin::toolsUrl()) . '">Assisted Tools</a>)' : '') . '.</p></div>';
        }
        echo '<p class="description">Do not include personal information such as members\' names, contact details, or payment details. '
            . 'Training info is stored in your site\'s database like your pages and posts, and matching passages go to your service provider with each question.</p>';

        echo '<h2>Add training info</h2>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bgca_training_add');
        echo '<input type="hidden" name="action" value="bgca_training_add"><table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="bgca-file">Upload a file</label></th><td><input type="file" id="bgca-file" name="file" accept=".'
            . esc_attr(implode(',.', Extractor::TYPES)) . '"><p class="description">Word (.docx), OpenDocument (.odt), PDF, plain text, Markdown, CSV, or HTML, up to '
            . esc_html(size_format(min(Extractor::MAX_BYTES, wp_max_upload_size()))) . '. Only the text is kept; the file itself is not stored. '
            . 'Scanned PDFs have no text to read; paste the text instead.</p></td></tr>';
        echo '<tr><th scope="row"><label for="bgca-text">Or paste text</label></th><td><textarea id="bgca-text" name="text" rows="8" class="large-text"></textarea>'
            . '<p class="description">Headings on their own line (or Markdown <code>#</code> headings) help the assistant find the right section.</p></td></tr>';
        echo '<tr><th scope="row"><label for="bgca-title">Title</label></th><td><input type="text" id="bgca-title" name="title" class="regular-text" maxlength="180">'
            . '<p class="description">Leave blank to use the file name.</p></td></tr>';
        $this->audienceField('visitor', false);
        echo '</table><p><button class="button button-primary">Add training info</button></p></form>';

        echo '<h2>Your training info</h2>';
        if (!$entries) {
            echo '<p>Nothing yet. Start with a page of frequently asked questions.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th scope="col">Title</th><th scope="col">Used for</th><th scope="col">Size</th>'
                . '<th scope="col">Source</th><th scope="col">Updated</th><th scope="col">Actions</th></tr></thead><tbody>';
            foreach ($entries as $e) {
                $id = (int) $e->id;
                $used = $e->audience === 'staff' ? 'Staff only' : 'Visitors and staff';
                if ((int) $e->pinned === 1) $used .= ', always included';
                if ((int) $e->enabled !== 1) $used = 'Off';
                echo '<tr' . ((int) $e->enabled !== 1 ? ' style="opacity:.65"' : '') . '><td><strong><a href="' . esc_url(self::url(['edit' => $id])) . '">'
                    . esc_html((string) $e->title) . '</a></strong></td><td>' . esc_html($used) . '</td><td>'
                    . esc_html(number_format_i18n((int) $e->chars)) . ' characters</td><td>' . esc_html((string) ($e->source_name ?: 'Pasted text'))
                    . '</td><td>' . esc_html(mysql2date(get_option('date_format'), get_date_from_gmt((string) $e->updated_at))) . '</td><td>';
                echo '<a class="button button-small" href="' . esc_url(self::url(['edit' => $id])) . '">Edit</a> ';
                $this->inlineAction('toggle', $id, (int) $e->enabled === 1 ? 'Turn off' : 'Turn on');
                $this->inlineAction('delete', $id, 'Delete', 'Delete this training info? This cannot be undone.');
                echo '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p class="description">' . esc_html(number_format_i18n($total)) . ' of ' . esc_html(number_format_i18n(Training::MAX_TOTAL_CHARS)) . ' characters used.</p>';
        }

        $this->renderTest();
    }

    private function audienceField(string $audience, bool $pinned): void
    {
        echo '<tr><th scope="row">Who it is for</th><td><fieldset><legend class="screen-reader-text">Who it is for</legend>'
            . '<label><input type="radio" name="audience" value="visitor"' . checked('visitor', $audience, false) . '> Visitors and staff</label><br>'
            . '<label><input type="radio" name="audience" value="staff"' . checked('staff', $audience, false) . '> Staff only</label></fieldset></td></tr>';
        echo '<tr><th scope="row">Always include</th><td><label><input type="checkbox" name="pinned" value="1"' . checked(true, $pinned, false)
            . '> Send the start of this entry with every question</label></td></tr>';
    }

    private function inlineAction(string $action, int $id, string $label, string $confirm = ''): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline"'
            . ($confirm !== '' ? ' onsubmit="return confirm(' . esc_attr((string) wp_json_encode($confirm)) . ');"' : '') . '>';
        wp_nonce_field('bgca_training_' . $action . '_' . $id);
        echo '<input type="hidden" name="action" value="bgca_training_' . esc_attr($action) . '"><input type="hidden" name="id" value="' . $id . '">'
            . '<button class="button button-small' . ($action === 'delete' ? ' button-link-delete' : '') . '">' . esc_html($label) . '</button></form> ';
    }

    private function renderEdit(int $id): void
    {
        $e = Training::get($id);
        if (!$e) {
            echo '<p>That training info no longer exists. <a href="' . esc_url(self::url()) . '">Back to the list</a>.</p>';
            return;
        }
        echo '<p><a href="' . esc_url(self::url()) . '">&larr; All training info</a></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bgca_training_update_' . $id);
        echo '<input type="hidden" name="action" value="bgca_training_update"><input type="hidden" name="id" value="' . $id . '"><table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="bgca-title">Title</label></th><td><input type="text" id="bgca-title" name="title" class="regular-text" maxlength="180" value="'
            . esc_attr((string) $e->title) . '" required></td></tr>';
        $this->audienceField((string) $e->audience, (int) $e->pinned === 1);
        echo '<tr><th scope="row">On</th><td><label><input type="checkbox" name="enabled" value="1"' . checked(1, (int) $e->enabled, false)
            . '> The assistant uses this entry</label></td></tr>';
        echo '<tr><th scope="row"><label for="bgca-text">Text</label></th><td><textarea id="bgca-text" name="text" rows="24" class="large-text code">'
            . esc_textarea((string) $e->body) . '</textarea><p class="description">Fix anything the file conversion got wrong. '
            . esc_html(number_format_i18n(count(Training::chunk((string) $e->body)))) . ' passages.</p></td></tr>';
        echo '</table><p><button class="button button-primary">Save changes</button> <a class="button" href="' . esc_url(self::url()) . '">Cancel</a></p></form>';
    }

    private function renderTest(): void
    {
        $question = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
        $audience = ($_GET['as'] ?? '') === 'staff' ? 'staff' : 'visitor';
        echo '<h2 id="bgca-test">Test a question</h2><p>See which passages a question would send. This does not contact your service provider.</p>';
        echo '<form method="get" action="' . esc_url(admin_url(Plugin::coreActive() ? 'admin.php' : 'options-general.php')) . '#bgca-test">'
            . '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">'
            . '<input type="text" name="q" class="regular-text" value="' . esc_attr($question) . '" placeholder="When is the office open?" aria-label="Question"> '
            . '<select name="as" aria-label="Asked by"><option value="visitor"' . selected('visitor', $audience, false) . '>Asked by a visitor</option>'
            . '<option value="staff"' . selected('staff', $audience, false) . '>Asked by staff</option></select> '
            . '<button class="button">Show passages</button></form>';
        if ($question === '') return;
        $passages = Training::passages($audience, $question);
        if (!$passages) {
            echo '<p><strong>No passages match.</strong> The assistant would answer from public site facts only, and forward the question to your designated contact if those do not answer it.</p>';
            return;
        }
        $chars = 0;
        foreach ($passages as $p) $chars += Training::length($p['body']);
        echo '<p>' . count($passages) . ' passages, ' . esc_html(number_format_i18n($chars)) . ' characters:</p><ol>';
        foreach ($passages as $p) {
            $label = $p['title'] . ($p['heading'] !== '' && $p['heading'] !== $p['title'] ? ' > ' . $p['heading'] : '');
            echo '<li><strong>' . esc_html($label) . '</strong>' . ($p['pinned'] ? ' <em>(always included)</em>' : '')
                . '<div style="white-space:pre-wrap;max-width:60em;margin:.3em 0 1em;padding:.5em .75em;background:#fff;border-left:3px solid #c3c4c7">'
                . esc_html($p['body']) . '</div></li>';
        }
        echo '</ol>';
    }

    /* ── Actions ──────────────────────────────────────────────── */

    public function add(): void
    {
        $this->guard('bgca_training_add');
        $in = wp_unslash($_POST);
        $title = trim((string) ($in['title'] ?? ''));
        $text = (string) ($in['text'] ?? '');
        $sourceName = '';
        $sourceType = 'paste';
        $file = $_FILES['file'] ?? null;
        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $error = (int) $file['error'];
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                $this->back([['error', 'That file is larger than this server accepts (' . size_format(wp_max_upload_size()) . '). Split it, or paste the text instead.']]);
            }
            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                $this->back([['error', 'The upload did not arrive. Please try again.']]);
            }
            $sourceName = sanitize_file_name((string) $file['name']);
            $result = Extractor::fromFile((string) $file['tmp_name'], $sourceName);
            @unlink((string) $file['tmp_name']);
            if (!$result['ok']) $this->back([['error', esc_html($sourceName) . ': ' . esc_html($result['error'])]]);
            $text = trim($text) !== '' ? $text . "\n\n" . $result['text'] : $result['text'];
            $sourceType = strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));
            if ($title === '') $title = ucfirst(trim((string) preg_replace('/[-_]+/', ' ', pathinfo($sourceName, PATHINFO_FILENAME))));
        }
        if (trim($text) === '') $this->back([['error', 'Choose a file or paste some text.']]);
        if ($title === '') $title = wp_trim_words(Extractor::clean($text), 8, '');

        $saved = Training::save(0, [
            'title' => $title,
            'audience' => ($in['audience'] ?? '') === 'staff' ? 'staff' : 'visitor',
            'pinned' => !empty($in['pinned']),
            'enabled' => true,
            'source_name' => $sourceName,
            'source_type' => $sourceType,
        ], $text);
        if (is_wp_error($saved)) $this->back([['error', esc_html($saved->get_error_message())]]);
        $entry = Training::get($saved);
        $messages = [['success', '<strong>' . esc_html($title) . '</strong> added: ' . esc_html(number_format_i18n((int) ($entry->chars ?? 0))) . ' characters. '
            . 'Open it to check the text, then try it below with "Test a question".']];
        $messages = array_merge($messages, $this->privacyNotice((string) ($entry->body ?? '')));
        $this->back($messages);
    }

    public function update(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $this->guard('bgca_training_update_' . $id);
        $in = wp_unslash($_POST);
        $saved = Training::save($id, [
            'title' => (string) ($in['title'] ?? ''),
            'audience' => ($in['audience'] ?? '') === 'staff' ? 'staff' : 'visitor',
            'pinned' => !empty($in['pinned']),
            'enabled' => !empty($in['enabled']),
        ], (string) ($in['text'] ?? ''));
        if (is_wp_error($saved)) $this->back([['error', esc_html($saved->get_error_message())]], ['edit' => $id]);
        $entry = Training::get($id);
        $this->back(array_merge([['success', 'Saved.']], $this->privacyNotice((string) ($entry->body ?? ''))), ['edit' => $id]);
    }

    public function toggle(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $this->guard('bgca_training_toggle_' . $id);
        $entry = Training::get($id);
        if ($entry) Training::setEnabled($id, (int) $entry->enabled !== 1);
        $this->back([['success', $entry && (int) $entry->enabled !== 1 ? 'Turned on.' : 'Turned off. The assistant will not use it until you turn it back on.']]);
    }

    public function delete(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $this->guard('bgca_training_delete_' . $id);
        Training::delete($id);
        $this->back([['success', 'Deleted.']]);
    }

    /** @return list<array{0: string, 1: string}> */
    private function privacyNotice(string $text): array
    {
        $found = Training::privacyScan($text);
        if ($found['emails'] === 0 && $found['phones'] === 0) return [];
        $parts = [];
        if ($found['emails']) $parts[] = sprintf(_n('%s email address', '%s email addresses', $found['emails']), number_format_i18n($found['emails']));
        if ($found['phones']) $parts[] = sprintf(_n('%s phone number', '%s phone numbers', $found['phones']), number_format_i18n($found['phones']));
        return [['warning', 'This text contains ' . esc_html(implode(' and ', $parts)) . '. The assistant can repeat anything in its training info. '
            . 'Keep your organization\'s public contact details, and remove any that belong to individual members or staff who have not agreed to share them.']];
    }

    private function guard(string $nonce): void
    {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to change training info.');
        check_admin_referer($nonce);
        Training::maybeInstall();
    }

    /**
     * @param list<array{0: string, 1: string}> $messages
     * @param array<string, int|string> $args
     */
    private function back(array $messages, array $args = []): void
    {
        set_transient('bgca_notice_' . get_current_user_id(), $messages, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(self::url($args));
        exit;
    }
}
