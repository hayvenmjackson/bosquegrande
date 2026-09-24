<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Correspondence;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\ModuleInterface;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Modules\Constituents\ConstituentStore;

if (!defined('ABSPATH')) exit;

/**
 * Correspondence, the newsletter half (transactional mail stays in
 * Notifications). Newsletters are a CPT: compose in the block editor
 * or flip one checkbox and write raw HTML in a plain textarea; the
 * shell template is one editable piece of raw HTML. Subscriber groups
 * link to constituent records; subscribing is a confirm-link flow
 * (double opt-in), and unsubscribe links require explicit confirmation
 * before preferences change.
 */
final class CorrespondenceModule implements ModuleInterface
{
    public const CPT = 'bg_newsletter';

    public function name(): string
    {
        return 'correspondence';
    }

    public function label(): string
    {
        return 'Correspondence (newsletters and groups)';
    }

    public function activate(Container $container): void
    {
    }

    public function deactivate(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        add_filter('bg_wording_defaults', [self::class, 'wordingDefaults']);
        $container->set('correspondence.groups', fn(Container $c) => new Groups(
            $c->get('constituents.store'), $c->get('constituents.activity')
        ));
        $container->set('correspondence.group_csv_import', fn(Container $c) => new GroupCsvImport($c));
        $container->set('correspondence.sender', fn(Container $c) => new Sender(
            $c->get('constituents.store'), $c->get('correspondence.groups'), $c->get('logger')
        ));
        $container->get('correspondence.group_csv_import')->register();

        add_action('init', function (): void {
            register_post_type(self::CPT, [
                'labels' => [
                    'name' => 'Newsletters', 'singular_name' => 'Newsletter',
                    'add_new_item' => 'New newsletter', 'edit_item' => 'Edit newsletter',
                    // The sidebar's "Newsletters" entry is the compose
                    // screen; the post list is the archive.
                    'menu_name' => 'Newsletter Archive', 'all_items' => 'Newsletter Archive',
                ],
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => 'bosquegrande',
                'show_in_rest' => true,
                'supports' => ['title', 'editor'],
                'capability_type' => 'post',
                'map_meta_cap' => true,
            ]);
        });

        // The batch worker: send a slice, chain the next run a minute out.
        $container->get('jobs')->register(Sender::JOB, function (array $args) use ($container): void {
            $newsletterId = (int) ($args['newsletter'] ?? 0);
            if (!$newsletterId) return;
            /** @var Sender $sender */
            $sender = $container->get('correspondence.sender');
            $cap = $sender->dailyCap();
            if ($cap > 0 && $sender->sentToday() >= $cap) {
                // The relay's daily budget is spent; pause the whole run
                // until tomorrow rather than getting the account blocked.
                $container->get('logger')->info('correspondence',
                    'Daily send cap reached; the newsletter run resumes tomorrow.',
                    ['newsletter' => $newsletterId, 'cap' => $cap]);
                if (!$container->get('jobs')->enqueue(Sender::JOB, $args, 6 * HOUR_IN_SECONDS)) {
                    $container->get('logger')->error('correspondence',
                        'Newsletter run could not schedule its post-cap continuation.',
                        ['newsletter' => $newsletterId]);
                }
                return;
            }
            $remaining = $sender->runBatch($newsletterId);
            if ($remaining < 0) {
                // A transport refusal revokes the one-time authorization.
                // Staff must deliberately release the newsletter again.
                return;
            }
            if ($remaining > 0) {
                if (!$container->get('jobs')->enqueue(Sender::JOB, ['newsletter' => $newsletterId], 60)) {
                    $container->get('logger')->error('correspondence',
                        'Newsletter run has queued recipients but could not schedule its next batch.',
                        ['newsletter' => $newsletterId]);
                }
            }
        });

        // The compose screen and its scheduled-send job.
        (new ComposeScreen($container))->register();
        $container->get('jobs')->register(ComposeScreen::SCHEDULE_JOB, function (array $args) use ($container): void {
            ComposeScreen::runScheduled($container, $args);
        });

        add_action('add_meta_boxes_' . self::CPT, [$this, 'metaBoxes']);
        add_action('save_post_' . self::CPT, function (int $postId): void {
            if (!isset($_POST['bg_nl_nonce']) || !wp_verify_nonce((string) $_POST['bg_nl_nonce'], 'bg_nl_meta')) return;
            if (!current_user_can('edit_post', $postId)) return;
            update_post_meta($postId, Composer::RAW_FLAG, empty($_POST['bg_raw_mode']) ? '0' : '1');
            update_post_meta($postId, Composer::RAW_BODY, (string) wp_unslash((string) ($_POST['bg_raw_body'] ?? '')));
        });

        add_action('admin_menu', function (): void {
            add_submenu_page('bosquegrande', 'Subscriber Groups', 'Subscriber Groups',
                'manage_options', 'bosquegrande-groups', [$this, 'renderGroupsPage']);
            add_submenu_page('bosquegrande', 'Newsletter Templates', 'Newsletter Templates',
                'manage_options', 'bosquegrande-nl-template', [$this, 'renderTemplatePage']);
        }, 14);

        foreach (['group_add', 'group_sub', 'group_archive', 'template', 'nl_send', 'nl_test'] as $op) {
            add_action('admin_post_bg_' . $op, function () use ($container, $op): void {
                $method = 'handle' . str_replace('_', '', ucwords($op, '_'));
                $this->{$method}($container);
            });
        }

        // Optional newsletter opt-in from LGL. When Settings names an LGL
        // field and a pulled record carries a yes-ish value in it, the
        // person joins the default group. One-way: a no never
        // unsubscribes anyone, an existing row of any status is never
        // touched (a deliberate unsubscribe beats a stale flag), and the
        // suppression list still governs at send time.
        add_action('bg_lgl_pulled_constituent', function (int $localId, array $remote) use ($container): void {
            if (!self::lglNewsletterOptIn($remote)) return;
            /** @var Groups $groups */
            $groups = $container->get('correspondence.groups');
            $general = $groups->bySlug('general');
            if (!$general) return;
            if ($groups->subscriptionStatus($localId, (int) $general->id) !== null) return;
            $groups->subscribe($localId, (int) $general->id, 'lgl_sync');
        }, 10, 2);

        // Subscriptions on the constituent profile.
        add_action('bg_constituent_profile', function (int $constituentId) use ($container): void {
            $this->renderProfileSubscriptions($container, $constituentId);
        });

        add_shortcode('bg_subscribe', [$this, 'subscribeShortcode']);

        add_action('rest_api_init', function () use ($container): void {
            register_rest_route('bosquegrande/v1', '/subscribe', [
                'methods' => 'POST',
                'permission_callback' => '__return_true', // Public form; rate limited inside.
                'callback' => fn(\WP_REST_Request $req) => $this->restSubscribe($container, $req),
            ]);
            register_rest_route('bosquegrande/v1', '/unsubscribe', [
                'methods' => 'POST',
                'permission_callback' => '__return_true', // RFC 8058 one-click; token gated inside.
                'callback' => fn(\WP_REST_Request $req) => $this->restOneClickUnsub($container, $req),
            ]);
        });

        // Public landing pages: confirm and unsubscribe.
        add_action('template_redirect', function () use ($container): void {
            if (isset($_POST['bg_sub_confirm'])) {
                $this->landConfirm($container, sanitize_text_field((string) wp_unslash($_POST['bg_sub_confirm'])));
                exit;
            }
            if (isset($_POST['bg_unsub_confirm'])) {
                $this->landUnsubscribe($container, sanitize_text_field((string) wp_unslash($_POST['bg_unsub_confirm'])), false, true);
                exit;
            }
            if (isset($_POST['bg_unsub_all_confirm'])) {
                $this->landUnsubscribe($container, sanitize_text_field((string) wp_unslash($_POST['bg_unsub_all_confirm'])), true, true);
                exit;
            }
            if (isset($_GET['bg_sub_confirm'])) {
                $this->subscribeConfirmation(
                    $container,
                    sanitize_text_field((string) wp_unslash($_GET['bg_sub_confirm']))
                );
                exit;
            }
            if (isset($_GET['bg_unsub'])) {
                $this->landUnsubscribe($container, sanitize_text_field((string) $_GET['bg_unsub']), false, false);
                exit;
            }
            if (isset($_GET['bg_unsub_all'])) {
                $this->landUnsubscribe($container, sanitize_text_field((string) $_GET['bg_unsub_all']), true, false);
                exit;
            }
        });
    }

    /** @param array<string,array<string,mixed>> $defaults @return array<string,array<string,mixed>> */
    public static function wordingDefaults(array $defaults): array
    {
        $copy = [
            'newsletter_subscribe_button' => ['Newsletter signup: submit button', 'Subscribe'],
            'newsletter_email_label' => ['Newsletter signup: accessible email-field label', 'Email address'],
            'newsletter_email_placeholder' => ['Newsletter signup: email placeholder', 'you@example.com'],
            'newsletter_submitting' => ['Newsletter signup: submitting status', 'One moment...'],
            'newsletter_generic_error' => ['Newsletter signup: generic error', 'Something went wrong. Please try again.'],
            'newsletter_neutral_success' => ['Newsletter signup: confirmation-email notice', 'Almost there! Check your email and tap the confirmation link.'],
            'newsletter_invalid_email' => ['Newsletter signup: invalid email', 'Please enter a valid email address.'],
            'newsletter_confirm_subject_one' => ['Newsletter confirmation email: singular subject', 'Confirm your newsletter subscription'],
            'newsletter_confirm_subject_many' => ['Newsletter confirmation email: plural subject', 'Confirm your newsletter subscriptions'],
            'newsletter_confirm_email_intro' => ['Newsletter confirmation email: introduction', 'Tap each link below to confirm the newsletter choices you made.'],
            'newsletter_confirm_email_expiry' => ['Newsletter confirmation email: expiry notice ({hours})', 'Each confirmation link works for {hours} hours.'],
            'newsletter_confirm_email_ignore' => ['Newsletter confirmation email: ignore notice', "Didn't make these choices? Just ignore this message."],
            'newsletter_invalid_link_title' => ['Newsletter result: invalid-link heading', 'That link is not valid'],
            'newsletter_invalid_confirm_body' => ['Newsletter result: invalid confirmation link ({hours})', 'It may have expired (confirmation links work for {hours} hours). Head back and subscribe again.'],
            'newsletter_invalid_confirm_prompt_body' => ['Newsletter confirmation prompt: invalid link', 'It may have expired. Head back and subscribe again.'],
            'newsletter_stale_confirm_title' => ['Newsletter result: stale-confirmation heading', 'That confirmation is out of date'],
            'newsletter_stale_confirm_body' => ['Newsletter result: stale-confirmation explanation', 'Please submit your newsletter choice again so we can safely confirm your current preference.'],
            'newsletter_error_title' => ['Newsletter result: error heading', 'Something went wrong'],
            'newsletter_error_retry' => ['Newsletter result: retry explanation', 'We could not finish your subscription. Please try again later.'],
            'newsletter_error_person' => ['Newsletter result: person mismatch', 'We could not match this confirmation to the registered person. Please make the newsletter choice again.'],
            'newsletter_restricted_title' => ['Newsletter result: delivery-restricted heading', 'Subscription unavailable'],
            'newsletter_restricted_body' => ['Newsletter result: delivery-restricted explanation', 'We could not activate this newsletter because the address has a delivery restriction. Please contact {org} if you believe it is an error.'],
            'newsletter_newer_choice_title' => ['Newsletter result: newer-choice heading', 'Newer preference kept'],
            'newsletter_newer_choice_body' => ['Newsletter result: newer-choice explanation', 'A newer unsubscribe choice was recorded after this confirmation link was issued. We kept that choice. Submit a new newsletter signup if you now want to opt in.'],
            'newsletter_subscribed_title' => ['Newsletter result: subscribed heading', "You're in!"],
            'newsletter_subscribed_prefix' => ['Newsletter result: subscribed text before group name', 'You are subscribed to'],
            'newsletter_subscribed_suffix' => ['Newsletter result: subscribed text after group name', 'Welcome!'],
            'newsletter_invalid_unsubscribe_body' => ['Newsletter result: invalid unsubscribe link', 'Please use the unsubscribe link from a newsletter.'],
            'newsletter_stop_all_title' => ['Newsletter unsubscribe: stop-all heading', 'Stop all email?'],
            'newsletter_unsubscribe_prefix' => ['Newsletter unsubscribe: heading before group name', 'Unsubscribe from'],
            'newsletter_this_group' => ['Newsletter unsubscribe: missing group fallback', 'this newsletter'],
            'newsletter_unsubscribe_error' => ['Newsletter unsubscribe: save failure', 'We could not record the unsubscribe. Please contact the site administrator.'],
            'newsletter_unsubscribe_all_error' => ['Newsletter unsubscribe: complete opt-out failure', 'We could not record the complete opt-out. Please contact the site administrator.'],
            'newsletter_all_done_title' => ['Newsletter unsubscribe: all-done heading', 'All done'],
            'newsletter_all_done_body' => ['Newsletter unsubscribe: all-done explanation', 'You will not receive any more emails from us.'],
            'newsletter_unsubscribed_title' => ['Newsletter unsubscribe: success heading', "You're unsubscribed"],
            'newsletter_unsubscribed_prefix' => ['Newsletter unsubscribe: success text before group name', 'No more'],
            'newsletter_unsubscribed_suffix' => ['Newsletter unsubscribe: success text after group name', 'emails for you.'],
            'newsletter_unsubscribe_all_link' => ['Newsletter unsubscribe: stop-all follow-up link', 'Stop ALL emails from us'],
            'newsletter_unsubscribe_all_tail' => ['Newsletter unsubscribe: stop-all follow-up explanation', 'if you would rather hear nothing at all.'],
            'newsletter_unavailable_title' => ['Newsletter confirmation: unavailable heading', 'Newsletter unavailable'],
            'newsletter_unavailable_body' => ['Newsletter confirmation: unavailable explanation', 'That newsletter is no longer accepting subscriptions.'],
            'newsletter_confirm_page_title' => ['Newsletter confirmation page: heading', 'Confirm your subscription'],
            'newsletter_confirm_page_intro' => ['Newsletter confirmation page: introduction', 'Opening the email link does not subscribe you. Confirm below to join'],
            'newsletter_confirm_button' => ['Newsletter confirmation page: button', 'Confirm subscription'],
            'newsletter_confirm_cancel' => ['Newsletter confirmation page: cancel link', 'Return without subscribing'],
            'newsletter_confirm_skip' => ['Newsletter confirmation page: skip link', 'Skip to subscription confirmation'],
            'newsletter_confirm_document_title' => ['Newsletter confirmation page: browser title', 'Confirm newsletter subscription'],
            'newsletter_unsubscribe_confirm_intro' => ['Newsletter unsubscribe page: introduction', 'Opening an email link does not change your preferences. Confirm below to continue.'],
            'newsletter_unsubscribe_confirm_button' => ['Newsletter unsubscribe page: confirm button', 'Confirm unsubscribe'],
            'newsletter_unsubscribe_keep' => ['Newsletter unsubscribe page: cancel link', 'Keep my current preferences'],
            'newsletter_unsubscribe_skip' => ['Newsletter unsubscribe page: skip link', 'Skip to unsubscribe confirmation'],
            'newsletter_result_back_prefix' => ['Newsletter result: back-link prefix', 'Back to'],
            'newsletter_result_skip' => ['Newsletter result: skip link', 'Skip to confirmation result'],
        ];
        foreach ($copy as $key => [$label, $text]) {
            $defaults[$key] = ['label' => $label, 'text' => $text, 'group' => 'Newsletter'];
        }
        $defaults['newsletter_confirm_email_expiry']['required_tokens'] = ['hours'];
        $defaults['newsletter_invalid_confirm_body']['required_tokens'] = ['hours'];
        return $defaults;
    }

    private static function word(string $key, string $fallback): string
    {
        return function_exists('bg_str') ? (string) \bg_str($key, $fallback) : $fallback;
    }

    /** @param array<string, scalar|null> $tokens */
    private static function wordFormat(string $key, string $fallback, array $tokens): string
    {
        if (function_exists('bg_str_format')) {
            return (string) \bg_str_format($key, $fallback, $tokens);
        }
        $replace = [];
        foreach ($tokens as $name => $value) {
            $replace['{' . sanitize_key((string) $name) . '}'] = (string) $value;
        }
        return strtr(self::word($key, $fallback), $replace);
    }

    /* ── Edit-screen meta boxes ───────────────────────────────────── */

    public function metaBoxes(): void
    {
        add_meta_box('bg_nl_mode', 'Composing mode', function (\WP_Post $post): void {
            $raw = (string) get_post_meta($post->ID, Composer::RAW_FLAG, true) === '1';
            wp_nonce_field('bg_nl_meta', 'bg_nl_nonce');
            ?>
            <p><label><input type="checkbox" name="bg_raw_mode" value="1" <?php checked($raw); ?>>
                <strong>Compose in raw HTML</strong></label></p>
            <p class="description">Checked: the HTML below is the newsletter body, exactly as written, and the
            block editor above is ignored. Unchecked: the block editor is the body. Either way it lands inside
            the shell from the Newsletter Template screen.</p>
            <textarea name="bg_raw_body" rows="14" class="large-text code" placeholder="&lt;h2&gt;Hello&lt;/h2&gt;&lt;p&gt;Write plain HTML here.&lt;/p&gt;"><?php
                echo esc_textarea((string) get_post_meta($post->ID, Composer::RAW_BODY, true)); ?></textarea>
            <?php
        }, self::CPT, 'normal', 'high');

        add_meta_box('bg_nl_send', 'Send', function (\WP_Post $post): void {
            $container = \BosqueGrande\Core\Plugin::instance()->container();
            /** @var Groups $groups */
            $groups = $container->get('correspondence.groups');
            /** @var Sender $sender */
            $sender = $container->get('correspondence.sender');
            $ledger = $sender->ledger($post->ID);
            if ($ledger) {
                echo '<p><strong>This send so far:</strong> ';
                $parts = [];
                foreach ($ledger as $status => $n) {
                    $parts[] = $n . ' ' . $status;
                }
                echo esc_html(implode(', ', $parts)) . '</p>';
            }
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bg_nl_test'); ?>
                <input type="hidden" name="action" value="bg_nl_test">
                <input type="hidden" name="newsletter" value="<?php echo (int) $post->ID; ?>">
                <p><textarea name="to" rows="4" placeholder="Test addresses, one per line or separated by commas"
                    required style="width:100%"></textarea>
                <label style="display:block;margin:8px 0"><input type="checkbox" name="confirm_send" value="1" required>
                    I authorize this one test release.</label>
                <button class="button" style="margin-top:6px">Send test message(s)</button></p>
                <p class="description"><strong>Direct test delivery.</strong> Up to
                    <?php echo (int) Sender::TEST_RECIPIENT_LIMIT; ?> addresses; each receives a separate message.
                    The release stops after the first refusal.</p>
            </form>
            <hr>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  onsubmit="return confirm('Send this newsletter to the whole group now?');">
                <?php wp_nonce_field('bg_nl_send'); ?>
                <input type="hidden" name="action" value="bg_nl_send">
                <input type="hidden" name="newsletter" value="<?php echo (int) $post->ID; ?>">
                <p><select name="group_id" required style="width:100%">
                    <option value="">Send to group...</option>
                    <?php foreach ($groups->all() as $group): ?>
                        <option value="<?php echo (int) $group->id; ?>"><?php
                            echo esc_html($group->name . ' (' . $group->active_count . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
                <label style="display:block;margin:8px 0"><input type="checkbox" name="confirm_send" value="1" required>
                    I have reviewed the saved newsletter and selected group and authorize this one release.</label>
                <button class="button button-primary" style="margin-top:6px">Send to group</button></p>
                <p class="description">Save the newsletter first; the send uses the saved version. Delivery runs
                in the background in batches; progress shows here.</p>
            </form>
            <?php
        }, self::CPT, 'side', 'high');
    }

    /* ── Admin screens ────────────────────────────────────────────── */

    public function renderGroupsPage(): void
    {
        if (!current_user_can('manage_options')) return;
        $container = \BosqueGrande\Core\Plugin::instance()->container();
        /** @var Groups $groups */
        $groups = $container->get('correspondence.groups');
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) $_GET['bg_notice']) : '';
        $error = (int) ($_GET['bg_error'] ?? 0) === 1;
        ?>
        <div class="wrap"><h1>Subscriber Groups</h1>
        <?php if ($notice): ?><div class="notice <?php echo $error ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <p style="max-width:760px">Each group is its own newsletter audience (General, Hiking, Arts, and so
        on). People join through the subscribe form (shortcode
        <code>[bg_subscribe group="slug"]</code>, confirm-link required) or right here; every change lands on
        the person's timeline with a consent record. Unsubscribing is one click from any newsletter.</p>
        <table class="widefat striped" style="max-width:900px">
            <thead><tr><th>Group</th><th>Slug (for the shortcode)</th><th>Active subscribers</th><th>Action</th></tr></thead>
            <tbody>
            <?php $all = $groups->all();
            if (!$all) echo '<tr><td colspan="4">No groups yet.</td></tr>';
            foreach ($all as $group) {
                echo '<tr><td>' . esc_html((string) $group->name) . '</td><td><code>'
                    . esc_html((string) $group->slug) . '</code></td><td>' . (int) $group->active_count . '</td><td>';
                if ((string) $group->slug === 'general') {
                    echo '<span class="description">Default group (cannot be archived)</span>';
                } else {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(&quot;Remove this newsletter from compose screens and My Profile? Subscription history will be retained.&quot;);">';
                    wp_nonce_field('bg_group_archive_' . (int) $group->id);
                    echo '<input type="hidden" name="action" value="bg_group_archive"><input type="hidden" name="group_id" value="' . (int) $group->id . '"><button class="button button-small">Archive</button></form>';
                }
                echo '</td></tr>';
            } ?>
            </tbody>
        </table>
        <h2>Add a group</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('bg_group_add'); ?>
            <input type="hidden" name="action" value="bg_group_add">
            <input type="text" name="name" placeholder="Group name" required>
            <input type="text" name="slug" placeholder="slug" required>
            <button class="button">Add group</button>
        </form>
        <h2>Subscribe someone</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('bg_group_sub'); ?>
            <input type="hidden" name="action" value="bg_group_sub">
            <input type="email" name="email" placeholder="email@example.com" required>
            <select name="group_id" required>
                <?php foreach ($all as $group): ?>
                    <option value="<?php echo (int) $group->id; ?>"><?php echo esc_html($group->name); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button">Subscribe</button>
            <span class="description">Creates the constituent record if needed; recorded with source "admin".</span>
        </form>
        <h2>Import a subscriber list</h2>
        <p>Upload a Gaggle CSV, choose its destination group, then confirm how the file's columns map to contact fields.</p>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('bg_group_import_upload'); ?>
            <input type="hidden" name="action" value="bg_group_import_upload">
            <input type="file" name="group_csv" accept=".csv,text/csv" required>
            <select name="group_id" required>
                <option value="">Choose destination group...</option>
                <?php foreach ($all as $group): ?>
                    <option value="<?php echo (int) $group->id; ?>"><?php echo esc_html($group->name); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button">Upload and map CSV</button>
            <p class="description">Maximum 10 MiB and 50,000 subscriber rows. The source is kept in protected temporary storage and deleted after import.</p>
        </form>
        <?php
        $importToken = isset($_GET['bg_group_import'])
            ? sanitize_text_field((string) wp_unslash($_GET['bg_group_import']))
            : '';
        if ($importToken !== '') {
            /** @var GroupCsvImport $importer */
            $importer = $container->get('correspondence.group_csv_import');
            $descriptor = $importer->descriptorForCurrentUser($importToken);
            $group = $descriptor ? $groups->byId((int) ($descriptor['group_id'] ?? 0)) : null;
            if ($descriptor && $group) {
                $importer->renderMapping(
                    $importToken,
                    (array) ($descriptor['headers'] ?? []),
                    $group,
                    (string) ($descriptor['name'] ?? 'subscriber-list.csv')
                );
            } else {
                echo '<div class="notice notice-error"><p>The CSV mapping session is invalid or expired. Upload the file again.</p></div>';
            }
        }
        ?>
        </div>
        <?php
    }

    public function renderTemplatePage(): void
    {
        if (!current_user_can('manage_options')) return;
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field((string) $_GET['bg_notice']) : '';
        $all = Composer::templates();
        $slug = sanitize_key((string) ($_GET['tpl'] ?? 'classic'));
        if (!isset($all[$slug])) $slug = 'classic';
        $tpl = $all[$slug];
        $base = admin_url('admin.php?page=bosquegrande-nl-template');
        ?>
        <div class="wrap"><h1>Newsletter Templates</h1>
        <?php if ($notice): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <p style="max-width:760px">The shells newsletters are sent inside, as plain editable HTML; what you see is
        exactly what goes out, no hidden settings. Each newsletter picks its template on the compose screen.
        Tokens: <code>{content}</code> (the newsletter body), <code>{subject}</code>, <code>{org}</code>, and
        <code>{unsubscribe_url}</code>, which must appear in every template.</p>
        <p>
        <?php foreach ($all as $ts => $t): ?>
            <a class="button<?php echo $ts === $slug ? ' button-primary' : ''; ?>"
               href="<?php echo esc_url(add_query_arg('tpl', $ts, $base)); ?>"><?php echo esc_html($t['name']); ?></a>
        <?php endforeach; ?>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('bg_template'); ?>
            <input type="hidden" name="action" value="bg_template">
            <input type="hidden" name="tpl" value="<?php echo esc_attr($slug); ?>">
            <p><label>Template name
                <input type="text" name="tpl_name" value="<?php echo esc_attr($tpl['name']); ?>"
                    <?php echo $tpl['builtin'] ? 'readonly' : ''; ?>></label></p>
            <textarea name="template" rows="18" class="large-text code"><?php echo esc_textarea($tpl['html']); ?></textarea>
            <p><button class="button button-primary">Save template</button>
            <?php if ($tpl['builtin']): ?>
                <button class="button" name="reset" value="1"
                    onclick="return confirm('Replace this template with the shipped version?');">Reset to shipped version</button>
            <?php else: ?>
                <button class="button" name="delete" value="1"
                    onclick="return confirm('Delete this template? Newsletters using it fall back to Classic.');">Delete template</button>
            <?php endif; ?>
            </p>
        </form>
        <h2>Add a template</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('bg_template'); ?>
            <input type="hidden" name="action" value="bg_template">
            <input type="hidden" name="create" value="1">
            <p><label>Name <input type="text" name="tpl_name" required></label>
            <button class="button">Create (starts from Classic)</button></p>
        </form>
        </div>
        <?php
    }

    private function renderProfileSubscriptions(Container $container, int $constituentId): void
    {
        /** @var Groups $groups */
        $groups = $container->get('correspondence.groups');
        $subs = $groups->forConstituent($constituentId);
        if (!$subs) return;
        echo '<h2>Newsletter subscriptions</h2><table class="widefat striped" style="max-width:680px">';
        echo '<thead><tr><th>Group</th><th>Status</th><th>Source</th><th>Changed (UTC)</th></tr></thead><tbody>';
        foreach ($subs as $sub) {
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html((string) $sub->name), esc_html((string) $sub->status),
                esc_html((string) $sub->source), esc_html((string) $sub->updated_at));
        }
        echo '</tbody></table>';
    }

    /* ── Public subscribe form ────────────────────────────────────── */

    /** @param array<string, string>|string $atts */
    public function subscribeShortcode($atts): string
    {
        $atts = shortcode_atts(['group' => '', 'label' => ''], (array) $atts, 'bg_subscribe');
        $slug = sanitize_key((string) $atts['group']);
        if ($slug === '') return '';
        $buttonLabel = trim((string) $atts['label']);
        if ($buttonLabel === '') $buttonLabel = self::word('newsletter_subscribe_button', 'Subscribe');
        $endpoint = esc_url_raw(rest_url('bosquegrande/v1/subscribe'));
        $id = 'bg-sub-' . $slug . '-' . wp_rand(100, 999);
        $working = self::word('newsletter_submitting', 'One moment...');
        $genericError = self::word('newsletter_generic_error', 'Something went wrong. Please try again.');
        return '<form class="bg-subscribe" id="' . esc_attr($id) . '" style="display:flex;gap:8px;flex-wrap:wrap;max-width:460px">'
            . '<input type="email" name="email" required autocomplete="email" aria-label="' . esc_attr(self::word('newsletter_email_label', 'Email address')) . '" placeholder="' . esc_attr(self::word('newsletter_email_placeholder', 'you@example.com')) . '" style="flex:1;min-width:200px;padding:10px;border:1px solid #ccc;border-radius:8px">'
            . '<input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">'
            . '<input type="hidden" name="group" value="' . esc_attr($slug) . '">'
            . '<button type="submit" style="background:#2E7D4F;color:#fff;border:0;border-radius:999px;padding:10px 22px;font-weight:700;cursor:pointer">'
            . esc_html($buttonLabel) . '</button>'
            . '<p class="bg-subscribe-msg" role="status" style="flex-basis:100%;margin:4px 0 0;font-weight:600"></p></form>'
            . '<script>(function(){var f=document.getElementById(' . wp_json_encode($id) . ');if(!f)return;'
            . 'f.addEventListener("submit",function(e){e.preventDefault();'
            . 'var m=f.querySelector(".bg-subscribe-msg");m.textContent=' . wp_json_encode($working) . ';'
            . 'fetch(' . wp_json_encode($endpoint) . ',{method:"POST",body:new FormData(f)})'
            . '.then(function(r){return r.json();}).then(function(d){if(!d||typeof d.ok!=="boolean"||typeof d.message!=="string")throw new Error("invalid response");m.textContent=d.message;})'
            . '.catch(function(){m.textContent=' . wp_json_encode($genericError) . ';});});})();</script>';
    }

    private function restSubscribe(Container $container, \WP_REST_Request $req): \WP_REST_Response
    {
        $neutral = new \WP_REST_Response(['ok' => true,
            'message' => self::word('newsletter_neutral_success', 'Almost there! Check your email and tap the confirmation link.')], 200);
        if ((string) $req->get_param('website') !== '') return $neutral; // Honeypot.
        $email = ConstituentStore::normEmail((string) $req->get_param('email'));
        /** @var Groups $groups */
        $groups = $container->get('correspondence.groups');
        $group = $groups->bySlug((string) $req->get_param('group'));
        if (!is_email($email) || !$group) {
            return new \WP_REST_Response(['ok' => false, 'message' => self::word('newsletter_invalid_email', 'Please enter a valid email address.')], 400);
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $target = hash('sha256', $email);
        if (!self::claimPublicMailBudget('subscribe-ip:' . hash('sha256', $ip), 5, 15 * MINUTE_IN_SECONDS)
            || !self::claimPublicMailBudget('subscribe-email:' . $target, 3, 15 * MINUTE_IN_SECONDS)
            || !self::claimPublicMailBudget('subscribe-repeat:' . $target . ':' . (int) $group->id, 1, MINUTE_IN_SECONDS)
            || !self::claimPublicMailBudget('subscribe-global', 80, 15 * MINUTE_IN_SECONDS)
        ) return $neutral;

        self::requestSubscriptionConfirmations($container, $email, [(int) $group->id], 'subscribe_form');
        return $neutral;
    }

    /** Atomically reserve one anonymous email-send budget unit. */
    private static function claimPublicMailBudget(string $scope, int $limit, int $window): bool
    {
        $hash = md5($scope);
        $lock = 'bg_sub_mail_lock_' . $hash;
        $owner = OptionLease::acquire($lock, 10);
        if ($owner === '') return false;
        try {
            $key = 'bg_sub_mail_budget_' . $hash;
            $state = get_transient($key);
            $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
            if ($count >= $limit) return false;
            return set_transient($key, ['count' => $count + 1], max(1, $window));
        } finally {
            OptionLease::release($lock, $owner);
        }
    }

    /**
     * Send one neutral double-opt-in message for one or more active Groups.
     * A prior visitor-selected unsubscribe-all may be reversed after the
     * signed confirmation is consumed; every other suppression reason stays
     * silent and cannot be bypassed from a public form.
     *
     * @param array<int, mixed> $groupIds
     */
    public static function requestSubscriptionConfirmations(
        Container $container,
        string $email,
        array $groupIds,
        string $context = 'public_form',
        int $constituentId = 0
    ): bool {
        $email = ConstituentStore::normEmail($email);
        if (!is_email($email)) return false;
        /** @var Groups $groups */
        $groups = $container->get('correspondence.groups');
        $validIds = $groups->validActiveSelectionIds($groupIds);
        if (!$validIds) return true;

        $emailBidx = $container->get('encryption')->blindIndex($email);
        $suppressionReason = $groups->suppressionReason($emailBidx);
        if ($suppressionReason !== null && $suppressionReason !== 'unsubscribe-all') {
            // Preserve the public endpoint's enumeration-safe response.
            return true;
        }

        $lines = [];
        foreach ($validIds as $groupId) {
            $group = $groups->byId((int) $groupId);
            $token = $group ? Sender::confirmToken($email, (int) $groupId, $constituentId) : '';
            if (!$group || $token === '') {
                $container->get('logger')->error('correspondence', 'Subscription confirmation could not be signed.', [
                    'group' => (int) $groupId,
                    'context' => bg_substr($context, 0, 48),
                ]);
                return false;
            }
            $lines[] = (string) $group->name . ":\n"
                . add_query_arg('bg_sub_confirm', $token, home_url('/'));
        }

        $count = count($lines);
        $subject = $count === 1
            ? self::word('newsletter_confirm_subject_one', 'Confirm your newsletter subscription')
            : self::word('newsletter_confirm_subject_many', 'Confirm your newsletter subscriptions');
        // Links and group names are always appended as immutable functional
        // content; editing the surrounding prose cannot produce a linkless
        // confirmation email.
        $body = self::word('newsletter_confirm_email_intro', 'Tap each link below to confirm the newsletter choices you made.')
            . ' ' . get_bloginfo('name') . ". "
            . self::wordFormat(
                'newsletter_confirm_email_expiry',
                'Each confirmation link works for {hours} hours.',
                ['hours' => 48]
            ) . "\n\n"
            . implode("\n\n", $lines)
            . "\n\n" . self::word('newsletter_confirm_email_ignore', "Didn't make these choices? Just ignore this message.") . "\n";
        $sent = wp_mail($email, $subject, $body);
        $container->get('logger')->info(
            'correspondence',
            $sent ? 'Subscription confirmation sent.' : 'Subscription confirmation REFUSED by the mailer.',
            ['groups' => $count, 'context' => bg_substr($context, 0, 48)]
        );
        return (bool) $sent;
    }

    private function restOneClickUnsub(Container $container, \WP_REST_Request $req): \WP_REST_Response
    {
        $payload = Composer::verify((string) $req->get_param('t'));
        if (!$payload || ($payload['a'] ?? '') !== 'unsub') {
            return new \WP_REST_Response(['ok' => false], 400);
        }
        $ok = $container->get('correspondence.groups')->unsubscribe(
            (int) ($payload['c'] ?? 0), (int) ($payload['g'] ?? 0), 'one-click'
        );
        return new \WP_REST_Response(['ok' => $ok], $ok ? 200 : 503);
    }

    /* ── Landing pages ────────────────────────────────────────────── */

    private function landConfirm(Container $container, string $token): void
    {
        $payload = Sender::verifyConfirmToken($token);
        /** @var Groups $groups */
        $groups = $container->get('correspondence.groups');
        if (!$payload || ($payload['a'] ?? '') !== 'confirm') {
            $this->landingPage(
                self::word('newsletter_invalid_link_title', 'That link is not valid'),
                self::wordFormat(
                    'newsletter_invalid_confirm_body',
                    'It may have expired (confirmation links work for {hours} hours). Head back and subscribe again.',
                    ['hours' => 48]
                )
            );
        }
        $issuedAt = (int) ($payload['i'] ?? 0);
        if ($issuedAt < 1) {
            // Pre-ordering tokens cannot be proven newer than an opt-out.
            // Fail closed and ask for a freshly signed choice instead.
            $this->landingPage(
                self::word('newsletter_stale_confirm_title', 'That confirmation is out of date'),
                self::word('newsletter_stale_confirm_body', 'Please submit your newsletter choice again so we can safely confirm your current preference.')
            );
        }
        $group = $groups->byId((int) ($payload['g'] ?? 0));
        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        if (!$group || !$store->available()) {
            $this->landingPage(
                self::word('newsletter_error_title', 'Something went wrong'),
                self::word('newsletter_error_retry', 'We could not finish your subscription. Please try again later.')
            );
        }
        $email = ConstituentStore::normEmail((string) ($payload['e'] ?? ''));
        $emailBidx = $container->get('encryption')->blindIndex($email);
        // Registration-origin confirmations bind the signed request to the
        // exact final person, avoiding shared-household email ambiguity.
        // Legacy and standalone subscribe links retain the email-only path.
        $constituentId = (int) ($payload['c'] ?? 0);
        if ($constituentId > 0) {
            $record = $store->get($constituentId);
            if (!$record
                || ($record['status'] ?? '') !== 'active'
                || ConstituentStore::normEmail((string) ($record['email'] ?? '')) !== $email
            ) {
                $this->landingPage(
                    self::word('newsletter_error_title', 'Something went wrong'),
                    self::word('newsletter_error_person', 'We could not match this confirmation to the registered person. Please make the newsletter choice again.')
                );
            }
        } else {
            $constituentId = $store->upsertByEmail($email);
        }
        if (!$constituentId) {
            $this->landingPage(
                self::word('newsletter_error_title', 'Something went wrong'),
                self::word('newsletter_error_retry', 'We could not finish your subscription. Please try again later.')
            );
        }
        $confirmed = $groups->confirmIssuedSelection(
            $constituentId,
            (int) $group->id,
            $emailBidx,
            $issuedAt,
            'confirm-link'
        );
        if (in_array($confirmed, ['confirmed', 'restricted', 'stale'], true)) {
            Sender::forgetConfirmToken($token);
        }
        if ($confirmed === 'restricted') {
            $this->landingPage(
                self::word('newsletter_restricted_title', 'Subscription unavailable'),
                self::word('newsletter_restricted_body', 'We could not activate this newsletter because the address has a delivery restriction. Please contact {org} if you believe it is an error.')
            );
        }
        if ($confirmed === 'stale') {
            $this->landingPage(
                self::word('newsletter_newer_choice_title', 'Newer preference kept'),
                self::word('newsletter_newer_choice_body', 'A newer unsubscribe choice was recorded after this confirmation link was issued. We kept that choice. Submit a new newsletter signup if you now want to opt in.')
            );
        }
        if ($confirmed !== 'confirmed') {
            $this->landingPage(
                self::word('newsletter_error_title', 'Something went wrong'),
                self::word('newsletter_error_retry', 'We could not finish your subscription. Please try again later.')
            );
        }
        $this->landingPage(
            self::word('newsletter_subscribed_title', "You're in!"),
            esc_html(self::word('newsletter_subscribed_prefix', 'You are subscribed to')) . ' '
                . esc_html($group->name) . '. '
                . esc_html(self::word('newsletter_subscribed_suffix', 'Welcome!'))
        );
    }

    private function landUnsubscribe(Container $container, string $token, bool $all, bool $confirmed): void
    {
        $payload = Composer::verify($token);
        $expected = $all ? 'unsuball' : 'unsub';
        if (!$payload || ($payload['a'] ?? '') !== $expected) {
            $this->landingPage(
                self::word('newsletter_invalid_link_title', 'That link is not valid'),
                self::word('newsletter_invalid_unsubscribe_body', 'Please use the unsubscribe link from a newsletter.')
            );
        }
        /** @var Groups $groups */
        $groups = $container->get('correspondence.groups');
        $constituentId = (int) ($payload['c'] ?? 0);
        if (!$confirmed) {
            $group = !$all ? $groups->byId((int) ($payload['g'] ?? 0), true) : null;
            $this->unsubscribeConfirmation(
                $token,
                $all,
                $all
                    ? self::word('newsletter_stop_all_title', 'Stop all email?')
                    : self::word('newsletter_unsubscribe_prefix', 'Unsubscribe from') . ' '
                        . (string) ($group->name ?? self::word('newsletter_this_group', 'this newsletter')) . '?'
            );
        }
        if ($all) {
            /** @var ConstituentStore $store */
            $store = $container->get('constituents.store');
            $record = $store->get($constituentId);
            $suppressed = false;
            if ($record && ($record['email'] ?? '') !== '') {
                $suppressed = $groups->suppress(
                    $container->get('encryption')->blindIndex(ConstituentStore::normEmail($record['email'])),
                    'unsubscribe-all'
                );
            }
            $allUpdated = true;
            foreach ($groups->forConstituent($constituentId) as $sub) {
                if ($sub->status === 'active') {
                    $allUpdated = $groups->unsubscribe(
                        $constituentId,
                        (int) $sub->group_id,
                        'unsubscribe-all'
                    ) && $allUpdated;
                }
            }
            if (!$suppressed || !$allUpdated) {
                $this->landingPage(
                    self::word('newsletter_error_title', 'Something went wrong'),
                    self::word('newsletter_unsubscribe_all_error', 'We could not record the complete opt-out. Please contact the site administrator.')
                );
            }
            $this->landingPage(
                self::word('newsletter_all_done_title', 'All done'),
                self::word('newsletter_all_done_body', 'You will not receive any more emails from us.')
            );
        }
        $groupId = (int) ($payload['g'] ?? 0);
        if (!$groups->unsubscribe($constituentId, $groupId, 'link')) {
            $this->landingPage(
                self::word('newsletter_error_title', 'Something went wrong'),
                self::word('newsletter_unsubscribe_error', 'We could not record the unsubscribe. Please contact the site administrator.')
            );
        }
        $group = $groups->byId($groupId, true);
        $allToken = Composer::sign(['a' => 'unsuball', 'c' => $constituentId]);
        $allLink = $allToken !== ''
            ? ' <a href="' . esc_url(add_query_arg('bg_unsub_all', $allToken, home_url('/'))) . '">'
                . esc_html(self::word('newsletter_unsubscribe_all_link', 'Stop ALL emails from us')) . '</a> '
                . esc_html(self::word('newsletter_unsubscribe_all_tail', 'if you would rather hear nothing at all.'))
            : '';
        $this->landingPage(
            self::word('newsletter_unsubscribed_title', "You're unsubscribed"),
            esc_html(self::word('newsletter_unsubscribed_prefix', 'No more')) . ' '
            . esc_html($group->name ?? self::word('newsletter_this_group', 'this newsletter')) . ' '
            . esc_html(self::word('newsletter_unsubscribed_suffix', 'emails for you.'))
            . $allLink
        );
    }

    /** GET is display-only so mail-security scanners cannot confirm consent. */
    private function subscribeConfirmation(Container $container, string $token): void
    {
        $payload = Sender::verifyConfirmToken($token);
        if (!$payload || ($payload['a'] ?? '') !== 'confirm') {
            $this->landingPage(
                self::word('newsletter_invalid_link_title', 'That link is not valid'),
                self::word('newsletter_invalid_confirm_prompt_body', 'It may have expired. Head back and subscribe again.')
            );
        }
        $group = $container->get('correspondence.groups')->byId((int) ($payload['g'] ?? 0));
        if (!$group) {
            $this->landingPage(
                self::word('newsletter_unavailable_title', 'Newsletter unavailable'),
                self::word('newsletter_unavailable_body', 'That newsletter is no longer accepting subscriptions.')
            );
        }
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        status_header(200);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);
        $content = '<div role="region" aria-labelledby="bg-subscribe-confirm-title" style="max-width:520px;margin:60px auto;padding:0 16px"><div style="background:#fff;border:1px solid #D9E5D9;border-radius:16px;padding:28px">'
            . '<h1 id="bg-subscribe-confirm-title" style="margin:0 0 10px;color:#1F5130;font-size:1.4rem">' . esc_html(self::word('newsletter_confirm_page_title', 'Confirm your subscription')) . '</h1>'
            . '<p style="line-height:1.5">' . esc_html(self::word('newsletter_confirm_page_intro', 'Opening the email link does not subscribe you. Confirm below to join')) . ' ' . esc_html((string) $group->name) . '.</p>'
            . '<form method="post" action="' . esc_url(home_url('/')) . '">'
            . '<input type="hidden" name="bg_sub_confirm" value="' . esc_attr($token) . '">'
            . '<button type="submit" style="border:0;border-radius:999px;padding:11px 18px;background:#1F5130;color:#fff;font-weight:700;cursor:pointer">' . esc_html(self::word('newsletter_confirm_button', 'Confirm subscription')) . '</button>'
            . '</form><p style="margin:18px 0 0"><a href="' . esc_url(home_url('/')) . '" style="color:#1F5130">' . esc_html(self::word('newsletter_confirm_cancel', 'Return without subscribing')) . '</a></p>'
            . '</div></div>';
        $this->outputOwnedDocument($content, [
            'context' => 'subscription',
            'body_class' => 'bg-subscription-confirm-page',
            'main_id' => 'bg-subscription-confirm-main',
            'skip_label' => self::word('newsletter_confirm_skip', 'Skip to subscription confirmation'),
        ], self::word('newsletter_confirm_document_title', 'Confirm newsletter subscription'));
        exit;
    }

    /** GET is display-only so mail-security scanners cannot change preferences. */
    private function unsubscribeConfirmation(string $token, bool $all, string $title): void
    {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        status_header(200);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);
        $field = $all ? 'bg_unsub_all_confirm' : 'bg_unsub_confirm';
        $content = '<div role="region" aria-labelledby="bg-unsubscribe-confirm-title" style="max-width:520px;margin:60px auto;padding:0 16px"><div style="background:#fff;border:1px solid #D9E5D9;border-radius:16px;padding:28px">'
            . '<h1 id="bg-unsubscribe-confirm-title" style="margin:0 0 10px;color:#1F5130;font-size:1.4rem">' . esc_html($title) . '</h1>'
            . '<p style="line-height:1.5">' . esc_html(self::word('newsletter_unsubscribe_confirm_intro', 'Opening an email link does not change your preferences. Confirm below to continue.')) . '</p>'
            . '<form method="post" action="' . esc_url(home_url('/')) . '">'
            . '<input type="hidden" name="' . esc_attr($field) . '" value="' . esc_attr($token) . '">'
            . '<button type="submit" style="border:0;border-radius:999px;padding:11px 18px;background:#1F5130;color:#fff;font-weight:700;cursor:pointer">' . esc_html(self::word('newsletter_unsubscribe_confirm_button', 'Confirm unsubscribe')) . '</button>'
            . '</form><p style="margin:18px 0 0"><a href="' . esc_url(home_url('/')) . '" style="color:#1F5130">' . esc_html(self::word('newsletter_unsubscribe_keep', 'Keep my current preferences')) . '</a></p>'
            . '</div></div>';
        $this->outputOwnedDocument($content, [
            'context' => 'unsubscribe',
            'body_class' => 'bg-newsletter-unsubscribe-confirm-page',
            'main_id' => 'bg-newsletter-unsubscribe-main',
            'skip_label' => self::word('newsletter_unsubscribe_skip', 'Skip to unsubscribe confirmation'),
        ], $title);
        exit;
    }

    /** Renders a tiny branded page and exits. */
    private function landingPage(string $title, string $bodyHtml): void
    {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        status_header(200);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);
        $content = '<div role="region" aria-labelledby="bg-correspondence-result-title" style="max-width:520px;margin:60px auto;padding:0 16px">'
            . '<div style="background:#fff;border:1px solid #D9E5D9;border-radius:16px;padding:28px">'
            . '<h1 id="bg-correspondence-result-title" style="margin:0 0 10px;color:#1F5130;font-size:1.4rem">' . esc_html($title) . '</h1>'
            . '<p style="margin:0;font-size:1rem;line-height:1.5">' . wp_kses_post($bodyHtml) . '</p>'
            . '<p style="margin:18px 0 0"><a href="' . esc_url(home_url('/')) . '" style="color:#1F5130">' . esc_html(self::word('newsletter_result_back_prefix', 'Back to')) . ' '
            . esc_html(get_bloginfo('name')) . '</a></p>'
            . '</div></div>';
        $this->outputOwnedDocument($content, [
            'context' => 'correspondence',
            'body_class' => 'bg-correspondence-result-page',
            'main_id' => 'bg-correspondence-result-main',
            'skip_label' => self::word('newsletter_result_skip', 'Skip to confirmation result'),
        ], $title);
        exit;
    }

    /** Use BosqueGrande's public frame when available, retaining theme fallback. */
    private function outputOwnedDocument(string $content, array $args, string $documentTitle): void
    {
        $titleFilter = static fn(string $current): string => $documentTitle;
        $cleanUrl = (string) wp_json_encode(home_url('/'));
        $urlCleaner = static function () use ($cleanUrl): void {
            echo '<script id="bg-sensitive-url-cleaner">try{if(window.history&&window.history.replaceState){window.history.replaceState(null,"",' . $cleanUrl . ');}}catch(e){}</script>';
        };
        add_filter('pre_get_document_title', $titleFilter, 999);
        // Clear the bearer token from location.href before later wp_head hooks
        // can load third-party scripts; the confirmed POST keeps its own token.
        add_action('wp_head', $urlCleaner, -PHP_INT_MAX);
        if (function_exists('bgev_output_owned_document')) {
            \bgev_output_owned_document($content, $args);
        } else {
            get_header();
            echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer-owned markup.
            get_footer();
        }
        remove_action('wp_head', $urlCleaner, -PHP_INT_MAX);
        remove_filter('pre_get_document_title', $titleFilter, 999);
    }

    /* ── Admin-post handlers ──────────────────────────────────────── */

    private function backTo(string $page, string $notice): void
    {
        wp_safe_redirect(add_query_arg('bg_notice', rawurlencode($notice), admin_url('admin.php?page=' . $page)));
        exit;
    }

    private function handleGroupAdd(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_group_add');
        $id = $container->get('correspondence.groups')->create(
            (string) wp_unslash((string) ($_POST['slug'] ?? '')),
            sanitize_text_field((string) wp_unslash((string) ($_POST['name'] ?? '')))
        );
        $this->backTo('bosquegrande-groups', $id ? 'Group added.' : 'Not added: the slug is taken or invalid.');
    }

    private function handleGroupSub(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_group_sub');
        /** @var ConstituentStore $store */
        $store = $container->get('constituents.store');
        $constituentId = $store->upsertByEmail((string) wp_unslash((string) ($_POST['email'] ?? '')));
        if (!$constituentId) {
            $this->backTo('bosquegrande-groups', 'Not subscribed: bad email, the record cap, or encryption unavailable.');
        }
        $ok = $container->get('correspondence.groups')->subscribe($constituentId, (int) ($_POST['group_id'] ?? 0), 'admin');
        $this->backTo('bosquegrande-groups', $ok ? 'Subscribed.' : 'Not subscribed: unknown group.');
    }

    private function handleGroupArchive(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        $groupId = (int) ($_POST['group_id'] ?? 0);
        check_admin_referer('bg_group_archive_' . $groupId);
        $ok = $container->get('correspondence.groups')->archive($groupId);
        $this->backTo(
            'bosquegrande-groups',
            $ok ? 'Newsletter group archived. Existing consent history was retained.' : 'That group could not be archived.'
        );
    }

    private function handleTemplate(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_template');
        $slug = sanitize_key((string) ($_POST['tpl'] ?? 'classic'));
        $name = sanitize_text_field((string) wp_unslash((string) ($_POST['tpl_name'] ?? '')));

        if (!empty($_POST['create'])) {
            $newSlug = sanitize_key(sanitize_title($name));
            if ($newSlug === '' || isset(Composer::templates()[$newSlug])) {
                $this->backTo('bosquegrande-nl-template', 'Pick a name not already in use.');
            }
            Composer::saveTemplateSlug($newSlug, $name, Composer::templateHtml('classic'));
            wp_safe_redirect(add_query_arg(
                ['page' => 'bosquegrande-nl-template', 'tpl' => $newSlug, 'bg_notice' => rawurlencode('Template created; edit it below.')],
                admin_url('admin.php')
            ));
            exit;
        }
        if (!empty($_POST['delete'])) {
            Composer::deleteTemplateSlug($slug);
            $this->backTo('bosquegrande-nl-template', 'Template deleted; newsletters using it fall back to Classic.');
        }
        if (!empty($_POST['reset'])) {
            $slug === 'classic' ? Composer::saveTemplate('') : Composer::deleteTemplateSlug($slug);
            $this->backTo('bosquegrande-nl-template', 'Template reset to the shipped version.');
        }
        $html = (string) wp_unslash((string) ($_POST['template'] ?? ''));
        if (!str_contains($html, '{unsubscribe_url}')) {
            $this->backTo('bosquegrande-nl-template', 'Not saved: the template must contain {unsubscribe_url}.');
        }
        Composer::saveTemplateSlug($slug, $name, $html);
        $this->backTo('bosquegrande-nl-template', 'Template saved.');
    }

    private function handleNlSend(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_nl_send');
        if ((string) ($_POST['confirm_send'] ?? '') !== '1') {
            wp_die('Manual newsletter release was not authorized.');
        }
        $newsletterId = (int) ($_POST['newsletter'] ?? 0);
        $groupId = (int) ($_POST['group_id'] ?? 0);
        /** @var Sender $sender */
        $sender = $container->get('correspondence.sender');
        update_post_meta($newsletterId, Sender::AUTH_META, '1');
        $result = $sender->queueNewsletter($newsletterId, $groupId);
        $scheduled = $container->get('jobs')->enqueue(Sender::JOB, ['newsletter' => $newsletterId], 5);
        if (!$scheduled) delete_post_meta($newsletterId, Sender::AUTH_META);
        wp_safe_redirect(add_query_arg('bg_notice', rawurlencode(
            $result['queued'] . ' queued for delivery' . ($result['suppressed'] ? ', ' . $result['suppressed'] . ' suppressed' : '') . '.'
            . (!$scheduled ? ' WordPress could not schedule the delivery worker; check Site Health and WP-Cron.' : '')
        ), get_edit_post_link($newsletterId, 'url')));
        exit;
    }

    /**
     * Does this LGL record say the person opted into the
     * newsletter? Looks for an attribute or category whose NAME matches
     * the flag pattern (default: contains "mailchimp") and whose value
     * reads as yes. Both sides filterable: bg_lgl_newsletter_flag,
     * bg_lgl_newsletter_yes_values.
     * @param array<string, mixed> $remote
     */
    public static function lglNewsletterOptIn(array $remote): bool
    {
        $configured = (string) \BosqueGrande\Core\Plugin::instance()->container()->get('settings')->get('lgl_newsletter_flag', '');
        $namePattern = strtolower(trim((string) apply_filters('bg_lgl_newsletter_flag', $configured)));
        if ($namePattern === '') return false;
        $yes = array_map('strtolower', (array) apply_filters('bg_lgl_newsletter_yes_values',
            ['yes', 'y', 'true', '1', 'subscribed', 'opt-in', 'opt in', 'opted in', 'active']));

        foreach ((array) ($remote['custom_attrs'] ?? []) as $attr) {
            if (!is_array($attr)) continue;
            $name = strtolower((string) ($attr['classification'] ?? $attr['name'] ?? $attr['key'] ?? ''));
            if ($name === '' || !str_contains($name, $namePattern)) continue;
            $value = strtolower(trim((string) ($attr['value'] ?? $attr['description'] ?? '')));
            if (in_array($value, $yes, true)) return true;
        }
        foreach ((array) ($remote['categories'] ?? []) as $cat) {
            if (!is_array($cat)) continue;
            if (!str_contains(strtolower((string) ($cat['name'] ?? '')), $namePattern)) continue;
            foreach ((array) ($cat['keywords'] ?? []) as $kw) {
                $value = strtolower(trim((string) (is_array($kw) ? ($kw['name'] ?? '') : $kw)));
                if (in_array($value, $yes, true)) return true;
            }
        }
        return false;
    }

    private function handleNlTest(Container $container): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('bg_nl_test');
        if ((string) ($_POST['confirm_send'] ?? '') !== '1') {
            wp_die('Manual newsletter test release was not authorized.');
        }
        $newsletterId = (int) ($_POST['newsletter'] ?? 0);
        $parsed = Sender::parseTestRecipients((string) wp_unslash((string) ($_POST['to'] ?? '')));
        if ($parsed['invalid']) {
            $this->backToEdit($newsletterId, count($parsed['invalid']) . ' test address(es) were invalid.');
        }
        if ($parsed['overflow'] > 0) {
            $this->backToEdit($newsletterId,
                'Direct testing is limited to ' . Sender::TEST_RECIPIENT_LIMIT . ' addresses per send.');
        }
        if (!$parsed['valid']) {
            $this->backToEdit($newsletterId, 'Enter at least one valid test address.');
        }
        $result = $container->get('correspondence.sender')->testSendMany($newsletterId, $parsed['valid']);
        $reason = $result['failed'] > 0 ? (string) get_transient('bg_last_mail_error') : '';
        wp_safe_redirect(add_query_arg('bg_notice', rawurlencode(
            $result['sent'] . ' test message(s) sent'
                . ($result['failed'] ? ', ' . $result['failed'] . ' refused' : '')
                . ($reason !== '' ? '. The mailer said: "' . $reason . '".' : '. Check the inbox and spam folder.')
        ), get_edit_post_link($newsletterId, 'url')));
        exit;
    }

    private function backToEdit(int $newsletterId, string $notice): void
    {
        wp_safe_redirect(add_query_arg(
            'bg_notice',
            rawurlencode($notice),
            get_edit_post_link($newsletterId, 'url')
        ));
        exit;
    }
}
