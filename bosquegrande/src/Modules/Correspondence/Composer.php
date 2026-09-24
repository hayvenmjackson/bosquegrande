<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Correspondence;

if (!defined('ABSPATH')) exit;

/**
 * Turns a newsletter post into sendable email HTML, and signs the
 * links that ride in it.
 *
 * Two composing modes per newsletter, chosen on the edit screen:
 * blocks (the WordPress editor, rendered through do_blocks) or raw
 * HTML (a plain textarea, used verbatim). The shell template is one
 * editable piece of raw HTML (Newsletter Template screen) with
 * tokens: {content}, {subject}, {org}, {logo}, {unsubscribe_url}.
 */
final class Composer
{
    public const RAW_FLAG = '_bg_raw_html';
    public const RAW_BODY = '_bg_html_body';
    public const TEMPLATE_META = '_bg_nl_template';
    private const TEMPLATE_OPTION = 'bg_nl_template';
    private const TEMPLATES_OPTION = 'bg_nl_templates';

    public static function defaultTemplate(): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>{subject}</title></head>'
            . '<body style="margin:0;padding:0;background:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;color:#1F2A24">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:24px 0"><tr><td align="center">'
            . '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:10px;overflow:hidden">'
            . '<tr><td style="background:#1F5130;padding:20px 28px;color:#fff"><h1 style="margin:0;font-size:1.25rem;color:#fff">{org}</h1></td></tr>'
            . '<tr><td style="padding:28px">{content}</td></tr>'
            . '<tr><td style="padding:16px 28px;background:#fafafa;border-top:1px solid #eee;color:#888;font-size:.8rem">'
            . 'You are receiving this because you subscribed at {org}. '
            . '<a href="{unsubscribe_url}" style="color:#1F5130">Unsubscribe</a>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    public static function template(): string
    {
        $stored = (string) get_option(self::TEMPLATE_OPTION, '');
        return $stored !== '' ? $stored : self::defaultTemplate();
    }

    public static function saveTemplate(string $html): void
    {
        update_option(self::TEMPLATE_OPTION, $html, false);
    }

    /* ── The template library ──────────────────────────────── */

    /**
     * Every available template, slug => [name, html, builtin]. Built-ins
     * always exist; edits and additions live in one option and overlay
     * the shipped versions.
     * @return array<string, array{name: string, html: string, builtin: bool}>
     */
    public static function templates(): array
    {
        $out = [
            'classic' => ['name' => 'Classic', 'html' => self::template(), 'builtin' => true],
            'canopy' => ['name' => 'Canopy', 'html' => self::canopyTemplate(), 'builtin' => true],
            'bulletin' => ['name' => 'Bulletin', 'html' => self::bulletinTemplate(), 'builtin' => true],
            'plain' => ['name' => 'Plain', 'html' => self::plainTemplate(), 'builtin' => true],
        ];
        foreach ((array) get_option(self::TEMPLATES_OPTION, []) as $slug => $tpl) {
            if (!is_array($tpl)) continue;
            $slug = sanitize_key((string) $slug);
            if ($slug === '') continue;
            $out[$slug] = [
                'name' => (string) ($tpl['name'] ?? ucfirst($slug)),
                'html' => (string) ($tpl['html'] ?? ''),
                'builtin' => isset($out[$slug]),
            ];
        }
        return $out;
    }

    public static function templateHtml(string $slug): string
    {
        $all = self::templates();
        $slug = isset($all[$slug]) && $all[$slug]['html'] !== '' ? $slug : 'classic';
        return $all[$slug]['html'] !== '' ? $all[$slug]['html'] : self::defaultTemplate();
    }

    public static function saveTemplateSlug(string $slug, string $name, string $html): void
    {
        $slug = sanitize_key($slug);
        if ($slug === '') return;
        if ($slug === 'classic') {
            self::saveTemplate($html); // Classic is stored in its own option.
            return;
        }
        $stored = (array) get_option(self::TEMPLATES_OPTION, []);
        $stored[$slug] = ['name' => $name !== '' ? $name : ucfirst($slug), 'html' => $html];
        update_option(self::TEMPLATES_OPTION, $stored, false);
    }

    /** Custom templates only; built-ins revert to shipped HTML instead. */
    public static function deleteTemplateSlug(string $slug): void
    {
        $stored = (array) get_option(self::TEMPLATES_OPTION, []);
        unset($stored[sanitize_key($slug)]);
        update_option(self::TEMPLATES_OPTION, $stored, false);
    }

    private static function canopyTemplate(): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>{subject}</title></head>'
            . '<body style="margin:0;padding:0;background:#F2F7F2;font-family:Georgia,\'Times New Roman\',serif;color:#1F2A24">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#F2F7F2;padding:28px 0"><tr><td align="center">'
            . '<table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;background:#fff;border-radius:12px;overflow:hidden">'
            . '<tr><td style="height:6px;background:#C98A1B;font-size:0;line-height:0">&nbsp;</td></tr>'
            . '<tr><td style="background:#1F5130;padding:26px 32px">'
            . '<h1 style="margin:0;font-family:Georgia,serif;font-weight:normal;font-size:1.6rem;color:#fff">{org}</h1>'
            . '<p style="margin:6px 0 0;color:#C9DEC9;font-family:Arial,sans-serif;font-size:.8rem;letter-spacing:.08em;text-transform:uppercase">{subject}</p></td></tr>'
            . '<tr><td style="padding:32px;font-size:1.02rem;line-height:1.65">{content}</td></tr>'
            . '<tr><td style="padding:18px 32px;background:#1F5130;color:#C9DEC9;font-family:Arial,sans-serif;font-size:.78rem">'
            . 'You are receiving this because you subscribed at {org}. '
            . '<a href="{unsubscribe_url}" style="color:#C98A1B">Unsubscribe</a></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private static function bulletinTemplate(): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>{subject}</title></head>'
            . '<body style="margin:0;padding:0;background:#FFFDF4;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;color:#1F2A24">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#FFFDF4;padding:24px 0"><tr><td align="center">'
            . '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border:1px solid #D9E5D9">'
            . '<tr><td style="background:#C98A1B;padding:14px 28px;font-weight:700;font-size:.85rem;letter-spacing:.1em;text-transform:uppercase;color:#1F2A24">{org} &bull; Bulletin</td></tr>'
            . '<tr><td style="padding:26px 28px 6px"><h1 style="margin:0;font-size:1.35rem;color:#1F5130">{subject}</h1></td></tr>'
            . '<tr><td style="padding:12px 28px 28px;font-size:.98rem;line-height:1.6">{content}</td></tr>'
            . '<tr><td style="padding:14px 28px;border-top:3px solid #1F5130;color:#5A6A61;font-size:.78rem">'
            . 'Sent by {org}. <a href="{unsubscribe_url}" style="color:#1F5130">Unsubscribe</a></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private static function plainTemplate(): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>{subject}</title></head>'
            . '<body style="margin:0;padding:24px;background:#fff;font-family:Georgia,serif;color:#1F2A24">'
            . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
            . '<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;text-align:left">'
            . '<tr><td style="font-size:1rem;line-height:1.7">{content}</td></tr>'
            . '<tr><td style="padding-top:26px;border-top:1px solid #D9E5D9;color:#5A6A61;font-family:Arial,sans-serif;font-size:.76rem">'
            . '{org} &bull; <a href="{unsubscribe_url}" style="color:#1F5130">Unsubscribe</a></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** The inner body: raw HTML verbatim, or the block content rendered. */
    public static function body(\WP_Post $post): string
    {
        if ((string) get_post_meta($post->ID, self::RAW_FLAG, true) === '1') {
            return (string) get_post_meta($post->ID, self::RAW_BODY, true);
        }
        $content = (string) $post->post_content;
        return function_exists('do_blocks') ? do_blocks($content) : $content;
    }

    /** Full sendable HTML for one recipient, in the newsletter's chosen template. */
    public static function render(\WP_Post $post, string $unsubscribeUrl): string
    {
        $slug = (string) get_post_meta($post->ID, self::TEMPLATE_META, true);
        return self::fill(
            self::templateHtml($slug !== '' ? $slug : 'classic'),
            self::body($post),
            (string) $post->post_title,
            $unsubscribeUrl
        );
    }

    /** @param array{subject?: string, body?: string, template_html?: string} $snapshot */
    public static function renderSnapshot(array $snapshot, string $unsubscribeUrl): string
    {
        $template = (string) ($snapshot['template_html'] ?? '');
        if ($template === '') $template = self::defaultTemplate();
        return self::fill(
            $template,
            (string) ($snapshot['body'] ?? ''),
            (string) ($snapshot['subject'] ?? ''),
            $unsubscribeUrl
        );
    }

    /**
     * Single-pass token replacement, so text inside the body that happens to
     * look like a token is left alone. {logo} is the organization's logo, or
     * its name when no logo is set.
     */
    private static function fill(string $template, string $body, string $subject, string $unsubscribeUrl): string
    {
        $org = \BosqueGrande\Core\Organization::name();
        $logoUrl = \BosqueGrande\Core\Organization::logoUrl();
        $logo = $logoUrl !== ''
            ? '<img src="' . esc_url($logoUrl) . '" alt="' . esc_attr($org) . '" style="display:inline-block;max-width:200px;max-height:80px;height:auto;border:0">'
            : esc_html($org);
        return strtr($template, [
            '{content}' => $body,
            '{subject}' => esc_html($subject),
            '{org}' => esc_html($org),
            '{logo}' => $logo,
            '{unsubscribe_url}' => esc_url($unsubscribeUrl),
        ]);
    }

    /* ── Signed link tokens ───────────────────────────────────────── */

    /**
     * Stateless signed tokens for unsubscribe links and confirmation-link
     * compatibility. Current confirmations use opaque encrypted server-side
     * payloads; unsubscribe links remain stateless so they always work.
     * @param array<string, int|string> $payload
     */
    public static function sign(array $payload, int $ttlSeconds = 0): string
    {
        $payload['x'] = $ttlSeconds > 0 ? time() + $ttlSeconds : 0;
        $json = (string) wp_json_encode($payload);
        $key = self::key();
        if ($key === '') return '';
        $mac = hash_hmac('sha256', $json, $key);
        return rtrim(strtr(base64_encode($json . '|' . $mac), '+/', '-_'), '=');
    }

    /** @return array<string, int|string>|null Null on tamper or expiry. */
    public static function verify(string $token): ?array
    {
        if ($token === '' || strlen($token) > 1024) return null;
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false || !str_contains($raw, '|')) return null;
        $pos = strrpos($raw, '|');
        $json = substr($raw, 0, (int) $pos);
        $mac = substr($raw, (int) $pos + 1);
        $key = self::key();
        if ($key === '' || !hash_equals(hash_hmac('sha256', $json, $key), $mac)) return null;
        $payload = json_decode($json, true);
        if (!is_array($payload)) return null;
        $expires = (int) ($payload['x'] ?? 0);
        if ($expires > 0 && time() > $expires) return null;
        unset($payload['x']);
        return $payload;
    }

    private static function key(): string
    {
        return \BosqueGrande\Core\Plugin::instance()->container()->get('encryption')->signingKey('email-link');
    }
}
