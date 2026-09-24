<?php

declare(strict_types=1);

namespace BosqueGrande\Core;

use BosqueGrande\Modules\Constituents\LglFieldProfile;
use BosqueGrande\Modules\Exchange\EventbritePublisher;
use BosqueGrande\Modules\Lgl\LglClient;
use BosqueGrande\Modules\Registration\MonthlyDonor;
use BosqueGrande\Modules\Registration\RegistrationConfig;

if (!defined('ABSPATH')) exit;

/**
 * BosqueGrande > Settings: the organization's identity, registration
 * choices, monthly-donor rules, and the optional Little Green Light and
 * Eventbrite connections. Stripe, mail, and assistant keys have their own
 * screens; the status list at the top links to each.
 */
final class SettingsPage
{
    private const QUESTION_LABELS = [
        'demographics' => 'Demographic question',
        'age' => 'Age range',
        'heard' => 'How did you hear about this event?',
        'promote' => 'Will you help spread the word?',
        'relationship' => 'Relationship status',
    ];

    public function __construct(private Container $container)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', function () {
            add_submenu_page('bosquegrande', 'Settings', 'Settings',
                'manage_options', 'bosquegrande-settings', [$this, 'render']);
        }, 15);
        add_action('admin_enqueue_scripts', static function (string $hook): void {
            if (str_ends_with($hook, 'bosquegrande-settings')) wp_enqueue_media();
        });
        add_action('admin_post_bg_save_settings', [$this, 'save']);
        add_action('admin_post_bg_test_lgl', [$this, 'testLgl']);
        add_action('admin_post_bg_test_eventbrite', [$this, 'testEventbrite']);
        add_action('admin_post_bg_align_lgl_fields', [$this, 'alignLglFields']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) return;
        /** @var Settings $settings */
        $settings = $this->container->get('settings');
        $raw = get_option('bosquegrande_settings', []);
        $raw = is_array($raw) ? $raw : [];
        $notice = isset($_GET['bg_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['bg_notice'])) : '';
        $logoId = (int) ($raw['org_logo_id'] ?? 0);
        $logoUrl = Organization::logoUrl();
        ?>
        <div class="wrap bg-settings"><h1>BosqueGrande Settings</h1>
        <style>
          .bg-settings form{max-width:900px}
          .bg-settings .bg-status{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;max-width:900px;margin:14px 0 22px}
          .bg-settings .bg-status a{display:block;padding:10px 12px;border:1px solid #dcdcde;border-radius:6px;background:#fff;text-decoration:none;color:#1d2327}
          .bg-settings .bg-status strong{display:block}
          .bg-settings .bg-ok{color:#2A6B40}.bg-settings .bg-off{color:#646970}
          .bg-settings table.bg-rows{border-collapse:collapse;width:100%}
          .bg-settings table.bg-rows th,.bg-settings table.bg-rows td{padding:6px 8px;border-bottom:1px solid #e2e4e7;text-align:left;vertical-align:top}
          .bg-settings .bg-logo-preview img{max-height:64px;max-width:200px;border:1px solid #dcdcde;border-radius:4px;background:#fff;padding:4px}
        </style>
        <?php if ($notice !== '') : ?><div class="notice notice-info"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

        <h2>Your keys and connections</h2>
        <p style="max-width:760px">BosqueGrande runs on your own accounts. Nothing is shared with the plugin's authors;
        every key is stored encrypted on this site and never shown again.</p>
        <div class="bg-status">
          <?php foreach ($this->connectionStatus($settings) as [$label, $ok, $detail, $url]) : ?>
            <a href="<?php echo esc_url($url); ?>"><strong><?php echo esc_html($label); ?></strong>
              <span class="<?php echo $ok ? 'bg-ok' : 'bg-off'; ?>"><?php echo esc_html($detail); ?></span></a>
          <?php endforeach; ?>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field('bg_save_settings'); ?>
          <input type="hidden" name="action" value="bg_save_settings">

          <h2 class="title">Organization</h2>
          <table class="form-table" role="presentation">
            <tr><th scope="row"><label for="bg-org-name">Name</label></th>
              <td><input type="text" id="bg-org-name" name="org_name" class="regular-text" value="<?php echo esc_attr((string) ($raw['org_name'] ?? '')); ?>" placeholder="<?php echo esc_attr((string) get_option('blogname')); ?>">
              <p class="description">Shown in page headers, emails, and wording that says {org}. Blank uses the site title.</p></td></tr>
            <tr><th scope="row"><label for="bg-org-legal">Legal name</label></th>
              <td><input type="text" id="bg-org-legal" name="org_legal_name" class="regular-text" value="<?php echo esc_attr((string) ($raw['org_legal_name'] ?? '')); ?>">
              <p class="description">Shown before a visitor pays, so they know who is charging them. Blank uses the name above.</p></td></tr>
            <tr><th scope="row">Type</th>
              <td><?php $type = Organization::type(); ?>
              <label><input type="radio" name="org_type" value="nonprofit" <?php checked($type, 'nonprofit'); ?>> Nonprofit (money in is tracked as donations)</label><br>
              <label><input type="radio" name="org_type" value="business" <?php checked($type, 'business'); ?>> Business (money in is tracked as sales)</label></td></tr>
            <tr><th scope="row"><label for="bg-currency">Currency</label></th>
              <td><?php $currencyLocked = Money::locked(); ?>
              <select id="bg-currency" name="currency" <?php disabled($currencyLocked); ?>>
                <?php foreach (array_keys(Money::CURRENCIES) as $code): ?>
                  <option value="<?php echo esc_attr($code); ?>" <?php selected(Money::currency(), $code); ?>><?php echo esc_html(strtoupper($code)); ?></option>
                <?php endforeach; ?>
              </select>
              <p class="description"><?php echo esc_html($currencyLocked
                  ? 'Fixed because payments are already recorded in this currency.'
                  : 'Used for registration fees, donations, and exports. It can no longer be changed once a payment is recorded.'); ?></p></td></tr>
            <tr><th scope="row"><label for="bg-org-email">Contact email</label></th>
              <td><input type="email" id="bg-org-email" name="org_contact_email" class="regular-text" value="<?php echo esc_attr((string) ($raw['org_contact_email'] ?? '')); ?>" placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>">
              <p class="description">Where visitors are told to write, for example in registration confirmations.</p></td></tr>
            <tr><th scope="row"><label for="bg-org-phone">Contact phone</label></th>
              <td><input type="text" id="bg-org-phone" name="org_contact_phone" class="regular-text" value="<?php echo esc_attr((string) ($raw['org_contact_phone'] ?? '')); ?>">
              <p class="description">Optional. Left blank, no phone number is shown.</p></td></tr>
            <tr><th scope="row">Logo</th>
              <td><input type="hidden" id="bg-org-logo-id" name="org_logo_id" value="<?php echo (int) $logoId; ?>">
              <div class="bg-logo-preview" id="bg-logo-preview"><?php if ($logoUrl !== '') : ?><img src="<?php echo esc_url($logoUrl); ?>" alt=""><?php endif; ?></div>
              <p><button type="button" class="button" id="bg-logo-choose">Choose logo</button>
              <button type="button" class="button-link" id="bg-logo-clear" <?php echo $logoId > 0 ? '' : 'hidden'; ?>>Remove</button></p>
              <p class="description">Used in the public page header and emails. Without one, the site icon or the organization name is used.</p></td></tr>
            <tr><th scope="row">Attribution</th>
              <td><label><input type="checkbox" name="show_attribution" value="1" <?php checked(Organization::showAttribution()); ?>> Show a small “Powered by BosqueGrande” line in the public footer</label></td></tr>
          </table>

          <h2 class="title" id="bg-registration">Registration</h2>
          <table class="form-table" role="presentation">
            <tr><th scope="row">Registration form</th>
              <td><label><input type="checkbox" name="native_registration_enabled" value="1" <?php checked((int) $settings->get('native_registration_enabled', 1) === 1); ?>>
              Use BosqueGrande's registration form (unchecked: each event's Register button follows the event's own registration address)</label></td></tr>
            <tr><th scope="row">Registration options</th>
              <td><?php $this->renderTierRows(); ?>
              <p class="description">Every event offers all of these unless it chooses a subset in the event editor. A monthly-donor option is only for monthly donors when verification (below) is on. A volunteer option hides the “interested in volunteering” box, since choosing it already says so.</p></td></tr>
          </table>

          <h3>Questions</h3>
          <p style="max-width:760px">Optional questions on the registration form, My Profile, and the organizer app. Answers are encrypted,
          reported only in aggregate, and never sent to an assistant. One option per line; “Other” adds a “please specify” box and
          “Prefer not to say” clears the other choices.</p>
          <table class="bg-rows">
            <thead><tr><th>Question</th><th>Ask</th><th>Required</th><th>Options</th></tr></thead>
            <tbody>
            <?php foreach (self::QUESTION_LABELS as $key => $label) : $question = RegistrationConfig::question($key); ?>
              <tr><td><strong><?php echo esc_html($label); ?></strong></td>
                <td><input type="checkbox" name="questions[<?php echo esc_attr($key); ?>][enabled]" value="1" <?php checked($question['enabled']); ?> aria-label="<?php echo esc_attr('Ask: ' . $label); ?>"></td>
                <td><input type="checkbox" name="questions[<?php echo esc_attr($key); ?>][required]" value="1" <?php checked($question['required']); ?> aria-label="<?php echo esc_attr('Required: ' . $label); ?>"></td>
                <td><textarea name="questions[<?php echo esc_attr($key); ?>][options]" rows="4" class="large-text" aria-label="<?php echo esc_attr('Options: ' . $label); ?>"><?php echo esc_textarea(implode("\n", $question['options'])); ?></textarea></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <table class="form-table" role="presentation">
            <tr><th scope="row">Answers on file</th>
              <td><label><input type="checkbox" name="demographic_mask_on_file" value="1" <?php checked((int) $settings->get('demographic_mask_on_file', 0) === 1); ?>>
              Hide saved demographic answers on the form, showing only that answers are on file</label></td></tr>
            <tr><th scope="row"><label for="bg-ml-suppression">No confirmation emails for</label></th>
              <td><textarea id="bg-ml-suppression" name="ml_suppression" rows="3" class="large-text code"><?php echo esc_textarea((string) $settings->get('ml_suppression', '')); ?></textarea>
              <p class="description">One address per line. These addresses never receive registration confirmations; the on-screen response is unchanged.</p></td></tr>
          </table>

          <h2 class="title">Monthly donors</h2>
          <table class="form-table" role="presentation">
            <tr><th scope="row"><label for="bg-md-levels">Suggested monthly amounts</label></th>
              <td><textarea id="bg-md-levels" name="monthly_levels" rows="5" class="regular-text code"><?php echo esc_textarea(\BosqueGrande\Modules\Payments\MonthlyDonationEnrollment::levelsText()); ?></textarea>
              <p class="description">One per line: an amount in dollars, optionally followed by “= label”, for example <code>25 = Supporter</code>. Donors can always enter another amount.</p></td></tr>
            <tr><th scope="row">Verification</th>
              <td><?php $gate = (string) $settings->get(MonthlyDonor::GATE_OPTION, 'open'); ?>
                <label><input type="radio" name="monthly_donor_gate" value="open" <?php checked($gate !== 'enforced'); ?>>
                Open: anyone may choose a monthly-donor option (honor system). Each claim is logged.</label><br>
                <label><input type="radio" name="monthly_donor_gate" value="enforced" <?php checked($gate === 'enforced'); ?>>
                Verified: offered only when the person's record says Yes, their address is in the register below, or Stripe reports an active monthly subscription.</label></td></tr>
            <tr><th scope="row"><label for="bg-md-register">Monthly donor register</label></th>
              <td><textarea id="bg-md-register" name="monthly_donor_register" rows="4" class="large-text code"><?php echo esc_textarea((string) $settings->get(MonthlyDonor::REGISTER_OPTION, '')); ?></textarea>
              <p class="description">For monthly donors who give by cash or check. One address per line; always treated as monthly donors.</p></td></tr>
            <tr><th scope="row"><label for="bg-comp-register">Complimentary admission</label></th>
              <td><textarea id="bg-comp-register" name="complimentary_register" rows="4" class="large-text code"><?php echo esc_textarea((string) $settings->get('complimentary_register', '')); ?></textarea>
              <p class="description">One address per line. These people may choose a monthly-donor option without being donors; each use is logged.</p></td></tr>
          </table>

          <h2 class="title">Little Green Light (optional)</h2>
          <p class="description">Only needed if you keep donor records in Little Green Light. BosqueGrande works without it.</p>
          <table class="form-table" role="presentation">
            <tr><th scope="row"><label for="bg-lgl-key">API key</label></th>
              <td><input type="password" id="bg-lgl-key" name="lgl_api_key" class="regular-text" autocomplete="new-password"
                placeholder="<?php echo $settings->hasSecret('lgl_api_key') ? 'Saved (enter a new key to replace it)' : ''; ?>">
                <?php if ($settings->secretUnreadable('lgl_api_key')) : ?>
                  <span style="color:#b32d2e;font-weight:700">Unreadable; enter it again</span>
                <?php elseif ($settings->hasSecret('lgl_api_key')) : ?>
                  <span class="bg-ok" style="font-weight:700">&#10003; Saved</span>
                <?php endif; ?></td></tr>
            <tr><th scope="row"><label for="bg-lgl-demo">Category names</label></th>
              <td><p><label>Demographics <input type="text" id="bg-lgl-demo" name="demographic_lgl_category" class="regular-text" value="<?php echo esc_attr((string) $settings->get('demographic_lgl_category', 'Demographics')); ?>"></label></p>
              <p><label>Age range <input type="text" name="age_range_lgl_category" class="regular-text" value="<?php echo esc_attr((string) $settings->get('age_range_lgl_category', 'Age Range')); ?>"></label></p>
              <p><label>Relationship status <input type="text" name="relationship_lgl_category" class="regular-text" value="<?php echo esc_attr((string) $settings->get('relationship_lgl_category', 'Relationship Status')); ?>"></label></p>
              <p class="description">The Little Green Light constituent categories (or custom fields) that hold these answers. Close is good enough: matching tolerates punctuation, word order, and small typos.</p></td></tr>
            <tr><th scope="row"><label for="bg-lgl-code">Event code</label></th>
              <td><input type="text" id="bg-lgl-code" name="lgl_event_code" class="regular-text" value="<?php echo esc_attr((string) $settings->get('lgl_event_code', '')); ?>">
              <p class="description">Optional code stamped on events sent to Little Green Light.</p></td></tr>
            <tr><th scope="row"><label for="bg-lgl-nlflag">Newsletter opt-in field</label></th>
              <td><input type="text" id="bg-lgl-nlflag" name="lgl_newsletter_flag" class="regular-text" value="<?php echo esc_attr((string) $settings->get('lgl_newsletter_flag', '')); ?>">
              <p class="description">Optional. Name (or part of the name) of an LGL field that records newsletter consent. When a pulled record says yes there, the person joins the default subscriber group. Blank turns this off.</p></td></tr>
          </table>

          <h2 class="title">Eventbrite (optional)</h2>
          <p class="description">Used to import events and registrations from Eventbrite and to create or update Eventbrite drafts from your events.</p>
          <table class="form-table" role="presentation">
            <tr><th scope="row"><label for="bg-eb-token">Private token</label></th>
              <td><input type="password" id="bg-eb-token" name="eventbrite_token" class="regular-text" autocomplete="new-password"
                placeholder="<?php echo $settings->hasSecret('eventbrite_token') ? 'Saved (enter a new token to replace it)' : ''; ?>">
                <?php if ($settings->secretUnreadable('eventbrite_token')) : ?>
                  <span style="color:#b32d2e;font-weight:700">Unreadable; enter it again</span>
                <?php elseif ($settings->hasSecret('eventbrite_token')) : ?>
                  <span class="bg-ok" style="font-weight:700">&#10003; Saved</span>
                <?php endif; ?>
                <p class="description">In Eventbrite, open Account Settings, then Developer Links, then API Keys, and copy the private token.
                <a href="https://www.eventbrite.com/platform/docs/authentication" target="_blank" rel="noopener noreferrer">Eventbrite's instructions</a></p></td></tr>
            <tr><th scope="row"><label for="bg-eb-org">Organization ID</label></th>
              <td><input type="text" id="bg-eb-org" name="eventbrite_org_id" inputmode="numeric" pattern="[0-9]+" class="regular-text" value="<?php echo esc_attr((string) $settings->get('eventbrite_org_id', '')); ?>">
              <p class="description">Save, then use the connection test below to list the organizations your token can see.</p></td></tr>
          </table>

          <p><button class="button button-primary">Save settings</button></p>
        </form>

        <h2>Connection tests</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px">
          <?php wp_nonce_field('bg_test_eventbrite'); ?>
          <input type="hidden" name="action" value="bg_test_eventbrite">
          <button class="button">Test Eventbrite</button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px">
          <?php wp_nonce_field('bg_test_lgl'); ?>
          <input type="hidden" name="action" value="bg_test_lgl">
          <button class="button">Test Little Green Light</button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
          <?php wp_nonce_field('bg_align_lgl_fields'); ?>
          <input type="hidden" name="action" value="bg_align_lgl_fields">
          <button class="button">Add missing Little Green Light fields</button>
        </form>
        <p class="description" style="max-width:640px;margin-top:8px">The last button reads the custom fields defined in your
        Little Green Light account and creates any that BosqueGrande does not have yet. It never changes Little Green Light and
        never deletes a local field or value.</p>
        </div>
        <script>
        (function(){
          var choose=document.getElementById('bg-logo-choose'),clear=document.getElementById('bg-logo-clear');
          var input=document.getElementById('bg-org-logo-id'),preview=document.getElementById('bg-logo-preview');
          if(!choose||!window.wp||!wp.media)return;
          var frame;
          choose.addEventListener('click',function(){
            if(!frame){
              frame=wp.media({title:'Choose a logo',library:{type:'image'},multiple:false});
              frame.on('select',function(){
                var file=frame.state().get('selection').first().toJSON();
                input.value=file.id;
                preview.textContent='';
                var img=document.createElement('img');img.src=(file.sizes&&file.sizes.medium?file.sizes.medium.url:file.url);img.alt='';
                preview.appendChild(img);clear.hidden=false;
              });
            }
            frame.open();
          });
          clear.addEventListener('click',function(){input.value='0';preview.textContent='';clear.hidden=true;});
        })();
        </script>
        <?php
    }

    private function renderTierRows(): void
    {
        $rows = RegistrationConfig::tiers();
        for ($i = 0; $i < 2; $i++) {
            $rows[] = ['key' => '', 'label' => '', 'amount_cents' => 0, 'gated' => false, 'volunteer_chain' => false];
        }
        echo '<table class="bg-rows"><thead><tr><th>Label</th><th>Price</th><th>Monthly donors only</th><th>Volunteer option</th><th>Remove</th></tr></thead><tbody>';
        foreach ($rows as $index => $row) {
            $name = 'tiers[' . (int) $index . ']';
            $amount = $row['amount_cents'] > 0 ? number_format($row['amount_cents'] / 100, 2, '.', '') : ($row['key'] === '' ? '' : '0');
            echo '<tr>';
            echo '<td><input type="hidden" name="' . esc_attr($name . '[key]') . '" value="' . esc_attr($row['key']) . '">'
                . '<input type="text" class="regular-text" name="' . esc_attr($name . '[label]') . '" value="' . esc_attr($row['label']) . '"'
                . ($row['key'] === '' ? ' placeholder="Add an option"' : '') . ' aria-label="Option label"></td>';
            echo '<td>' . esc_html(Money::symbol()) . '<input type="text" inputmode="decimal" style="width:90px" name="' . esc_attr($name . '[amount]') . '" value="' . esc_attr($amount) . '" aria-label="Price"></td>';
            echo '<td><input type="checkbox" name="' . esc_attr($name . '[gated]') . '" value="1" ' . checked($row['gated'], true, false) . ' aria-label="Monthly donors only"></td>';
            echo '<td><input type="checkbox" name="' . esc_attr($name . '[volunteer_chain]') . '" value="1" ' . checked($row['volunteer_chain'], true, false) . ' aria-label="Volunteer option"></td>';
            echo '<td>' . ($row['key'] !== '' ? '<input type="checkbox" name="' . esc_attr($name . '[remove]') . '" value="1" aria-label="Remove this option">' : '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /** @return list<array{0: string, 1: bool, 2: string, 3: string}> */
    public function connectionStatus(Settings $settings): array
    {
        $stripe = $this->container->has('stripe') && $this->container->get('stripe')->isConfigured();
        $assistant = (int) $settings->get('ai_enabled', 0) === 1
            && ($settings->hasSecret('anthropic_api_key') || $settings->hasSecret('openai_api_key') || $settings->hasSecret('gemini_api_key'));
        $mailFrom = (string) $settings->get('mail_from_address', '');
        return [
            ['Stripe (payments)', $stripe, $stripe ? 'Connected' : 'Not connected', admin_url('admin.php?page=bosquegrande-payments')],
            ['Email sending', $mailFrom !== '', $mailFrom !== '' ? 'Sender set' : 'Using WordPress defaults', admin_url('admin.php?page=bosquegrande-mail')],
            ['Assistant (optional)', $assistant, $assistant ? 'On' : 'Off', admin_url('admin.php?page=bosquegrande-ai')],
            ['Little Green Light (optional)', $settings->hasSecret('lgl_api_key'), $settings->hasSecret('lgl_api_key') ? 'Key saved' : 'Not used', admin_url('admin.php?page=bosquegrande-settings#bg-lgl-key')],
            ['Eventbrite (optional)', $settings->hasSecret('eventbrite_token'), $settings->hasSecret('eventbrite_token') ? 'Token saved' : 'Not used', admin_url('admin.php?page=bosquegrande-settings#bg-eb-token')],
            ['Contacts', true, 'Import a spreadsheet', admin_url('admin.php?page=bosquegrande-import-studio')],
        ];
    }

    public function save(): void
    {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bg_save_settings');
        /** @var Settings $settings */
        $settings = $this->container->get('settings');
        $post = wp_unslash($_POST);

        Organization::save([
            'org_name' => (string) ($post['org_name'] ?? ''),
            'org_legal_name' => (string) ($post['org_legal_name'] ?? ''),
            'org_contact_email' => (string) ($post['org_contact_email'] ?? ''),
            'org_contact_phone' => (string) ($post['org_contact_phone'] ?? ''),
            'org_logo_id' => (int) ($post['org_logo_id'] ?? 0),
            'show_attribution' => !empty($post['show_attribution']),
        ]);
        update_option('bg_org_type', ($post['org_type'] ?? '') === 'business' ? 'business' : 'nonprofit', false);
        if (isset($post['currency'])) Money::save(sanitize_key((string) $post['currency']));

        $tiers = isset($post['tiers']) && is_array($post['tiers']) ? array_values($post['tiers']) : [];
        RegistrationConfig::saveTiers(array_map(static fn($row): array => is_array($row) ? $row : [], $tiers));
        $questions = isset($post['questions']) && is_array($post['questions']) ? $post['questions'] : [];
        RegistrationConfig::saveQuestions($questions);

        $secretsSaved = true;
        foreach (['lgl_api_key', 'eventbrite_token'] as $secret) {
            $value = trim((string) ($post[$secret] ?? ''));
            if ($value !== '') $secretsSaved = $settings->set($secret, $value) && $secretsSaved;
        }
        $settings->set('demographic_lgl_category', sanitize_text_field((string) ($post['demographic_lgl_category'] ?? 'Demographics')));
        $settings->set('age_range_lgl_category', sanitize_text_field((string) ($post['age_range_lgl_category'] ?? 'Age Range')));
        $settings->set('relationship_lgl_category', sanitize_text_field((string) ($post['relationship_lgl_category'] ?? 'Relationship Status')));
        $settings->set('lgl_event_code', sanitize_text_field((string) ($post['lgl_event_code'] ?? '')));
        $settings->set('lgl_newsletter_flag', sanitize_text_field((string) ($post['lgl_newsletter_flag'] ?? '')));
        $orgId = preg_replace('/\D+/', '', (string) ($post['eventbrite_org_id'] ?? ''));
        $settings->set('eventbrite_org_id', (string) $orgId);
        $settings->set('native_registration_enabled', empty($post['native_registration_enabled']) ? 0 : 1);
        $gate = (string) ($post['monthly_donor_gate'] ?? 'open');
        $settings->set(MonthlyDonor::GATE_OPTION, $gate === 'enforced' ? 'enforced' : 'open');
        $settings->set(MonthlyDonor::REGISTER_OPTION, sanitize_textarea_field((string) ($post['monthly_donor_register'] ?? '')));
        if (isset($post['monthly_levels'])) {
            \BosqueGrande\Modules\Payments\MonthlyDonationEnrollment::saveLevels(sanitize_textarea_field((string) $post['monthly_levels']));
        }
        $settings->set('complimentary_register', sanitize_textarea_field((string) ($post['complimentary_register'] ?? '')));
        $settings->set('demographic_mask_on_file', empty($post['demographic_mask_on_file']) ? 0 : 1);
        $settings->set('ml_suppression', sanitize_textarea_field((string) ($post['ml_suppression'] ?? '')));

        $this->back($secretsSaved
            ? 'Settings saved.'
            : 'Settings saved, except one or more keys: encrypted storage is unavailable, so they were not stored.');
    }

    public function testLgl(): void
    {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bg_test_lgl');
        /** @var LglClient $lgl */
        $lgl = $this->container->get('lgl');
        if (!$lgl->hasKey()) {
            $this->back('No Little Green Light key is saved yet.');
        }
        $attrs = $lgl->listCustomAttributes();
        if (!$attrs) {
            $this->back('Little Green Light answered, but returned no custom fields. If you expected some, check the key.');
        }
        $list = implode('; ', array_map(
            fn($a) => (string) ($a['id'] ?? '?') . ' = ' . (string) ($a['name'] ?? '?'),
            array_slice($attrs, 0, 20)
        ));
        $this->back('Little Green Light connected. Custom fields: ' . $list);
    }

    /** Read-only Eventbrite identity and organization visibility test. */
    public function testEventbrite(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') wp_die('POST required.', '', ['response' => 405]);
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bg_test_eventbrite');
        if (!$this->container->has('exchange.eventbrite.publisher')) {
            $this->back('The Eventbrite connector is unavailable.');
        }
        /** @var EventbritePublisher $publisher */
        $publisher = $this->container->get('exchange.eventbrite.publisher');
        $result = $publisher->testConnection();
        if (is_wp_error($result)) $this->back($result->get_error_message());

        $organizations = array_map(
            static fn(array $row): string => (string) $row['name'] . ' (' . (string) $row['id'] . ')',
            array_slice((array) $result['organizations'], 0, 20)
        );
        $summary = $organizations ? implode('; ', $organizations) : 'no organizations.';
        $this->back(sprintf(
            'Eventbrite connected as account %s, which can see: %s This test only reads; the first draft you send confirms write access.',
            (string) $result['user_id'],
            $summary
        ));
    }

    /**
     * Create local definitions for any custom field defined in the connected
     * Little Green Light account. Local, additive, and idempotent.
     */
    public function alignLglFields(): void
    {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('bg_align_lgl_fields');
        /** @var LglClient $lgl */
        $lgl = $this->container->get('lgl');
        if (!$lgl->hasKey()) {
            $this->back('No Little Green Light key is saved yet.');
        }
        /** @var \BosqueGrande\Modules\Constituents\ConstituentStore $store */
        $store = $this->container->get('constituents.store');
        if (!$store->available()) {
            $this->back('Encrypted contact storage is unavailable, so no fields were added.');
        }
        if (!$this->container->get('schema')->repairAnswerStorage()) {
            $this->back('The custom-field tables could not be repaired. Check System Status for the database error, then try again.');
        }

        $attributes = $lgl->listCustomAttributes();
        $result = LglFieldProfile::alignLiveAttributes($store, $attributes);
        $this->container->get('logger')->info('constituents', 'Little Green Light field alignment completed.', $result);
        if ($result['remote'] === 0) {
            $this->back('Little Green Light answered, but returned no custom fields to compare.');
        }
        $this->back(sprintf(
            'Compared %d Little Green Light field(s): %d already here, %d added, %d failed.',
            $result['remote'],
            $result['matched'],
            $result['created'],
            $result['failed']
        ));
    }

    private function back(string $notice): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => 'bosquegrande-settings', 'bg_notice' => rawurlencode($notice)],
            admin_url('admin.php')
        ));
        exit;
    }
}
