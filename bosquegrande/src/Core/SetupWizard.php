<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

if (!defined('ABSPATH')) exit;

/**
 * First-run setup: six short steps, each skippable and revisitable from
 * BosqueGrande > Setup. Nothing in the plugin requires it.
 *
 * 1. Your organization (name, type, contact, currency)
 * 2. Email sender
 * 3. Data protection (keyring self-test and recovery download)
 * 4. Your keys (Stripe, mail relay, assistant, LGL, Eventbrite)
 * 5. Your contacts and vocabulary
 * 6. Done
 */
final class SetupWizard
{
    private const PAGE = 'bosquegrande-setup';
    private const STEPS = 6;

    public function boot(Container $container): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', 'Setup', 'Setup',
                'manage_options', self::PAGE, [$this, 'render'], 90);
        }, 90);

        add_action('admin_post_bg_wizard_save', function () use ($container): void {
            $this->handleSave($container);
        });

        // One-time redirect into the wizard after activation.
        add_action('admin_init', function (): void {
            if (!get_transient('bg_do_wizard') || wp_doing_ajax() || !current_user_can('manage_options')) return;
            delete_transient('bg_do_wizard');
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE));
            exit;
        });
    }

    public static function markForFirstRun(): void
    {
        if (!get_option('bg_wizard_done')) {
            set_transient('bg_do_wizard', 1, 300);
        }
    }

    /** The Revenue noun for the org type: Donations or Sales. */
    public static function revenueNoun(): string
    {
        return Organization::type() === 'business' ? 'Sales' : 'Donations';
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) return;
        $container = Plugin::instance()->container();
        /** @var Settings $settings */
        $settings = $container->get('settings');
        $step = isset($_GET['step']) ? max(1, min(self::STEPS, (int) $_GET['step'])) : 1;
        $raw = get_option('bosquegrande_settings', []);
        $raw = is_array($raw) ? $raw : [];
        $link = static fn(string $page): string => admin_url('admin.php?page=' . $page);
        ?>
        <div class="wrap" style="max-width:760px">
            <h1>Set up BosqueGrande</h1>
            <p>Six short steps. Skip any of them and come back later from BosqueGrande &gt; Setup.</p>
            <p><strong>Step <?php echo (int) $step; ?> of <?php echo (int) self::STEPS; ?></strong></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bg_wizard_save'); ?>
                <input type="hidden" name="action" value="bg_wizard_save">
                <input type="hidden" name="step" value="<?php echo (int) $step; ?>">

                <?php if ($step === 1): ?>
                    <h2>Your organization</h2>
                    <p>Used in page headers, emails, receipts, and anywhere the wording says {org}.</p>
                    <table class="form-table" role="presentation">
                        <tr><th scope="row"><label for="bgw-name">Name</label></th>
                            <td><input type="text" id="bgw-name" name="org_name" class="regular-text"
                                placeholder="<?php echo esc_attr((string) get_option('blogname')); ?>"
                                value="<?php echo esc_attr((string) ($raw['org_name'] ?? '')); ?>"></td></tr>
                        <tr><th scope="row"><label for="bgw-legal">Legal name</label></th>
                            <td><input type="text" id="bgw-legal" name="org_legal_name" class="regular-text"
                                value="<?php echo esc_attr((string) ($raw['org_legal_name'] ?? '')); ?>">
                            <p class="description">Shown to people before they pay. Blank uses the name above.</p></td></tr>
                        <tr><th scope="row">Type</th>
                            <td><?php $type = Organization::type(); ?>
                            <label><input type="radio" name="org_type" value="nonprofit" <?php checked($type, 'nonprofit'); ?>> Nonprofit (money in is tracked as donations)</label><br>
                            <label><input type="radio" name="org_type" value="business" <?php checked($type, 'business'); ?>> Business (money in is tracked as sales)</label></td></tr>
                        <tr><th scope="row"><label for="bgw-email">Contact email</label></th>
                            <td><input type="email" id="bgw-email" name="org_contact_email" class="regular-text"
                                placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>"
                                value="<?php echo esc_attr((string) ($raw['org_contact_email'] ?? '')); ?>"></td></tr>
                        <tr><th scope="row"><label for="bgw-phone">Contact phone</label></th>
                            <td><input type="text" id="bgw-phone" name="org_contact_phone" class="regular-text"
                                value="<?php echo esc_attr((string) ($raw['org_contact_phone'] ?? '')); ?>">
                            <p class="description">Optional.</p></td></tr>
                        <tr><th scope="row"><label for="bgw-currency">Currency</label></th>
                            <td><select id="bgw-currency" name="currency" <?php disabled(Money::locked()); ?>>
                                <?php foreach (array_keys(Money::CURRENCIES) as $code): ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected(Money::currency(), $code); ?>><?php echo esc_html(strtoupper($code)); ?></option>
                                <?php endforeach; ?>
                            </select></td></tr>
                    </table>
                    <p>The logo and the rest of these details live under
                    <a href="<?php echo esc_url($link('bosquegrande-settings')); ?>">Settings</a>.</p>

                <?php elseif ($step === 2): ?>
                    <h2>Who does your email come from?</h2>
                    <p>The name and address on registration confirmations, reminders, and newsletters.
                    <a href="<?php echo esc_url($link('bosquegrande-mail')); ?>">Correspondence</a> adds a
                    mail relay and a test send.</p>
                    <table class="form-table" role="presentation">
                        <tr><th scope="row"><label for="bgw-from-name">Sender name</label></th>
                            <td><input type="text" id="bgw-from-name" name="mail_from_name" class="regular-text"
                                placeholder="<?php echo esc_attr(Organization::name()); ?>"
                                value="<?php echo esc_attr((string) $settings->get('mail_from_name', '')); ?>"></td></tr>
                        <tr><th scope="row"><label for="bgw-from-addr">Sender address</label></th>
                            <td><input type="email" id="bgw-from-addr" name="mail_from_address" class="regular-text"
                                placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>"
                                value="<?php echo esc_attr((string) $settings->get('mail_from_address', '')); ?>">
                            <p class="description">Use an address at your own domain for the best deliverability.</p></td></tr>
                    </table>

                <?php elseif ($step === 3): ?>
                    <h2>Data protection</h2>
                    <?php
                    /** @var Encryption $encryption */
                    $encryption = $container->get('encryption');
                    /** @var KeyStore $keys */
                    $keys = $container->get('keys');
                    $health = $encryption->healthCheck();
                    if ($health['ok']): ?>
                        <p><span class="dashicons dashicons-yes" style="color:#2E7D4F"></span>
                        <strong>Ready.</strong> Personal records, answers, and saved keys are encrypted with key
                        <code><?php echo esc_html($health['key_id']); ?></code>, and the self-test passed.
                        BosqueGrande repeats this check daily.</p>
                        <p><a class="button" href="<?php echo esc_url(wp_nonce_url(
                            admin_url('admin-post.php?action=bg_crypto_backup'),
                            'bg_crypto_backup'
                        )); ?>">Download the keyring backup</a></p>
                        <p class="description">Keep it in a password manager or other encrypted storage, away from
                        this server. Without <code><?php echo esc_html(basename($keys->path())); ?></code> or this
                        backup, encrypted records cannot be read.</p>
                    <?php else: ?>
                        <p><strong>Encryption is not ready:</strong> <?php echo esc_html($health['status']); ?>.</p>
                        <p>BosqueGrande will not store personal data until it is. Make
                        <code><?php echo esc_html(dirname($keys->path())); ?></code> writable by WordPress and reload
                        this page, or define <code>BG_KEYRING_PATH</code> in <code>wp-config.php</code> to put the
                        keyring in a writable folder outside the web root.</p>
                    <?php endif; ?>

                <?php elseif ($step === 4): ?>
                    <h2>Your keys</h2>
                    <p>BosqueGrande uses your own accounts. Each key is stored encrypted on this site and each
                    service stays off until you add it. All of these are optional.</p>
                    <ul class="ul-disc">
                        <?php foreach ((new SettingsPage($container))->connectionStatus($settings) as [$label, $ok, $detail, $url]): ?>
                            <li><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>:
                                <span style="color:<?php echo $ok ? '#2A6B40' : '#646970'; ?>"><?php echo esc_html($detail); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <h3>Assisted tools</h3>
                    <p>Optional writing, search, and review help through your own Anthropic, OpenAI, or Gemini key.
                    Individual records and demographic answers are never sent.</p>
                    <p><label><input type="radio" name="ai_posture" value="off" <?php checked((int) $settings->get('ai_enabled', 0) !== 1); ?>>
                        Not now</label><br>
                    <label><input type="radio" name="ai_posture" value="on" <?php checked((int) $settings->get('ai_enabled', 0) === 1); ?>>
                        Turn on (add the key under <a href="<?php echo esc_url($link('bosquegrande-ai')); ?>">Assisted Tools</a>)</label></p>

                <?php elseif ($step === 5): ?>
                    <h2>Your contacts and events</h2>
                    <p>BosqueGrande starts empty. Bring in your people and describe your events:</p>
                    <ol>
                        <li><a href="<?php echo esc_url($link('bosquegrande-import-studio')); ?>">Import Studio</a>:
                            upload a CSV, TSV, or Excel file of contacts, map its columns, and check a dry run before anything is saved.</li>
                        <li><a href="<?php echo esc_url($link('bosquegrande-connectors')); ?>">Connectors</a>:
                            pull contacts from Little Green Light once its key is saved.</li>
                        <li><a href="<?php echo esc_url($link('bosquegrande-tagging')); ?>">Tags &amp; Regions</a>:
                            list the regions you serve and the interests people filter events by.</li>
                        <li><a href="<?php echo esc_url($link('bosquegrande-settings')); ?>#bg-registration">Registration options</a>:
                            set what people can choose when they register (free, member, supporter, and so on) and which questions you ask.</li>
                    </ol>

                <?php else: ?>
                    <h2>You're set</h2>
                    <p>Add your first event under <a href="<?php echo esc_url(admin_url('edit.php?post_type=bg_event')); ?>">Events</a>,
                    then put the <code>[bosquegrande]</code> shortcode on a page to publish the calendar.
                    Everything here stays editable under <a href="<?php echo esc_url($link('bosquegrande-settings')); ?>">Settings</a>.</p>
                <?php endif; ?>

                <p style="margin-top:20px">
                    <?php if ($step < self::STEPS): ?>
                        <button class="button button-primary">Save and continue</button>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE . '&step=' . ($step + 1))); ?>">Skip</a>
                    <?php else: ?>
                        <button class="button button-primary">Finish</button>
                    <?php endif; ?>
                </p>
            </form>
        </div>
        <?php
    }

    private function handleSave(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_wizard_save');
        /** @var Settings $settings */
        $settings = $container->get('settings');
        $post = wp_unslash($_POST);
        $step = max(1, min(self::STEPS, (int) ($post['step'] ?? 1)));

        if ($step === 1) {
            Organization::save([
                'org_name' => (string) ($post['org_name'] ?? ''),
                'org_legal_name' => (string) ($post['org_legal_name'] ?? ''),
                'org_contact_email' => (string) ($post['org_contact_email'] ?? ''),
                'org_contact_phone' => (string) ($post['org_contact_phone'] ?? ''),
            ]);
            update_option('bg_org_type', ($post['org_type'] ?? '') === 'business' ? 'business' : 'nonprofit', false);
            if (isset($post['currency'])) Money::save(sanitize_key((string) $post['currency']));
        } elseif ($step === 2) {
            $name = sanitize_text_field((string) ($post['mail_from_name'] ?? ''));
            $addr = sanitize_email((string) ($post['mail_from_address'] ?? ''));
            if ($name !== '') $settings->set('mail_from_name', $name);
            if ($addr !== '') $settings->set('mail_from_address', $addr);
        } elseif ($step === 4) {
            $settings->set('ai_enabled', ((string) ($post['ai_posture'] ?? 'off')) === 'on' ? 1 : 0);
        } elseif ($step === self::STEPS) {
            update_option('bg_wizard_done', 1, false);
            $container->get('logger')->info('lifecycle', 'Setup wizard finished.');
            wp_safe_redirect(admin_url('admin.php?page=bosquegrande'));
            exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE . '&step=' . min(self::STEPS, $step + 1)));
        exit;
    }
}
