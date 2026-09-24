<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Correspondence;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\OptionLease;
use BosqueGrande\Modules\Constituents\ConstituentStore;
use BosqueGrande\Modules\Imports\SpreadsheetReader;

if (!defined('ABSPATH')) exit;

/** Administrator-only CSV import into one subscriber group. */
final class GroupCsvImport
{
    private const MAX_UPLOAD = 10485760; // 10 MiB.
    private const MAX_ROWS = 50000;
    private const TOKEN_TTL = HOUR_IN_SECONDS;

    /** @return array<string,array{label:string,aliases:list<string>}> */
    public static function targets(): array
    {
        return [
            'email' => ['label' => 'Email address', 'aliases' => ['email', 'email address', 'member email', 'subscriber email', 'primary email']],
            'first_name' => ['label' => 'First name', 'aliases' => ['first name', 'firstname', 'given name', 'member first name']],
            'last_name' => ['label' => 'Last name', 'aliases' => ['last name', 'lastname', 'surname', 'family name', 'member last name']],
            'full_name' => ['label' => 'Full name', 'aliases' => ['name', 'full name', 'member name', 'subscriber name']],
            'phone' => ['label' => 'Phone', 'aliases' => ['phone', 'phone number', 'mobile', 'mobile phone', 'cell', 'cell phone']],
            'address1' => ['label' => 'Address line 1', 'aliases' => ['address', 'address 1', 'address line 1', 'street', 'street address']],
            'address2' => ['label' => 'Address line 2', 'aliases' => ['address 2', 'address line 2', 'apartment', 'suite', 'unit']],
            'city' => ['label' => 'City', 'aliases' => ['city', 'town']],
            'state' => ['label' => 'State', 'aliases' => ['state', 'province', 'region']],
            'postal' => ['label' => 'Postal code', 'aliases' => ['postal', 'postal code', 'zip', 'zip code', 'postcode']],
            'notes' => ['label' => 'Notes', 'aliases' => ['notes', 'note', 'comments', 'member notes']],
        ];
    }

    public function __construct(private Container $container)
    {
    }

    public function register(): void
    {
        add_action('admin_post_bg_group_import_upload', [$this, 'upload']);
        add_action('admin_post_bg_group_import_commit', [$this, 'commit']);
        add_action('bg_group_csv_cleanup', [$this, 'cleanupScheduled'], 10, 2);
    }

    public function upload(): void
    {
        $this->authorize('bg_group_import_upload');
        $groupId = max(0, (int) ($_POST['group_id'] ?? 0));
        /** @var Groups $groups */
        $groups = $this->container->get('correspondence.groups');
        if (!$groups->byId($groupId)) $this->redirect('Choose an existing subscriber group.', true);

        $file = $_FILES['group_csv'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->redirect('The CSV upload did not complete.', true);
        }
        $name = sanitize_file_name((string) ($file['name'] ?? ''));
        $size = max(0, (int) ($file['size'] ?? 0));
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
            $this->redirect('Use a .csv file exported from Gaggle or another mailing-list service.', true);
        }
        if ($size < 1 || $size > self::MAX_UPLOAD || !is_uploaded_file($tmp)) {
            $this->redirect('The upload is empty, larger than 10 MiB, or could not be verified.', true);
        }

        $directory = $this->directory();
        if (!wp_mkdir_p($directory)) $this->redirect('BosqueGrande could not create protected import storage.', true);
        $this->protectDirectory($directory);
        $this->removeExpiredFiles($directory);
        $token = bin2hex(random_bytes(24));
        $path = $directory . '/' . hash('sha256', $token) . '.csv';
        if (!move_uploaded_file($tmp, $path)) $this->redirect('BosqueGrande could not secure the uploaded file.', true);
        @chmod($path, 0600);

        try {
            /** @var SpreadsheetReader $reader */
            $reader = $this->container->get('imports.reader');
            $inventory = $reader->inventory($path, 'csv');
            $rows = (int) ($inventory['sheets'][0]['rows'] ?? 0);
            if ($rows < 2) throw new \RuntimeException('The CSV needs a header row and at least one subscriber row.');
            if ($rows > self::MAX_ROWS + 1) throw new \RuntimeException('This group import is limited to 50,000 subscriber rows.');
            $headerResult = $reader->headers($path, 'csv');
            $headers = array_map([$this, 'cleanHeader'], (array) ($headerResult['cells'] ?? []));
            if (!$headers) throw new \RuntimeException('No readable CSV header row was found.');
            if (count(array_filter($headers, static fn(string $header): bool => $header !== '')) !== count(array_unique(array_filter($headers)))) {
                throw new \RuntimeException('CSV column headings must be distinct before they can be mapped.');
            }
            $descriptor = [
                'path' => $path,
                'name' => $name,
                'group_id' => $groupId,
                'user_id' => get_current_user_id(),
                'headers' => $headers,
                'created_at' => time(),
            ];
            if (!set_transient($this->transientKey($token), $descriptor, self::TOKEN_TTL)) {
                throw new \RuntimeException('The temporary mapping session could not be created.');
            }
            wp_schedule_single_event(time() + self::TOKEN_TTL, 'bg_group_csv_cleanup', [$token, $path]);
            wp_safe_redirect(add_query_arg([
                'page' => 'bosquegrande-groups',
                'bg_group_import' => rawurlencode($token),
            ], admin_url('admin.php')));
            exit;
        } catch (\Throwable $e) {
            @unlink($path);
            $this->container->get('logger')->warn('correspondence', 'Subscriber-group CSV upload was rejected.', [
                'reason' => bg_substr($e->getMessage(), 0, 180),
            ]);
            $this->redirect($e->getMessage(), true);
        }
    }

    public function commit(): void
    {
        $this->authorize('bg_group_import_commit');
        $token = sanitize_text_field((string) wp_unslash((string) ($_POST['import_token'] ?? '')));
        $descriptor = $this->descriptor($token);
        if (!$descriptor) $this->redirect('That mapping session expired. Upload the CSV again.', true);
        $claimKey = $this->claimKey($token);
        $claimOwner = OptionLease::acquire($claimKey, self::TOKEN_TTL + 15 * MINUTE_IN_SECONDS);
        if ($claimOwner === '') {
            $this->redirect('That CSV import is already being processed.', true);
        }

        $path = (string) $descriptor['path'];
        try {
            /** @var Groups $groups */
            $groups = $this->container->get('correspondence.groups');
            /** @var ConstituentStore $store */
            $store = $this->container->get('constituents.store');
            $groupId = (int) $descriptor['group_id'];
            if (!$groups->byId($groupId)) throw new \RuntimeException('The selected subscriber group no longer exists.');
            if (!$store->available()) throw new \RuntimeException('The import cannot run until encryption is available.');

            $headers = (array) $descriptor['headers'];
            $posted = is_array($_POST['mapping'] ?? null) ? wp_unslash($_POST['mapping']) : [];
            $mapping = $this->mappingFromRequest((array) $posted, $headers);
            if (($mapping['email'] ?? 0) < 1) throw new \RuntimeException('Map the email-address column before importing.');

            $counts = [
                'rows' => 0, 'new_people' => 0, 'matched_people' => 0,
                'subscribed' => 0, 'already_active' => 0, 'unsubscribed' => 0,
                'invalid' => 0, 'failed' => 0,
            ];
            /** @var SpreadsheetReader $reader */
            $reader = $this->container->get('imports.reader');
            foreach ($reader->rows($path, 'csv', '', array_values(array_unique(array_filter($mapping)))) as $cells) {
                $counts['rows']++;
                if ($counts['rows'] > self::MAX_ROWS) throw new \RuntimeException('The CSV exceeded the 50,000-row safety limit.');
                $email = sanitize_email($this->cell($cells, $mapping, 'email'));
                if (!is_email($email)) {
                    $counts['invalid']++;
                    continue;
                }
                $data = $this->rowData($cells, $mapping);
                $before = $store->idsByEmail($email);
                $constituentId = $store->upsertByEmail($email, $data);
                if ($constituentId < 1) {
                    $counts['failed']++;
                    continue;
                }
                if (in_array($constituentId, $before, true)) $counts['matched_people']++;
                else $counts['new_people']++;

                $status = $groups->subscriptionStatus($constituentId, $groupId);
                if ($status === 'unsubscribed') {
                    $counts['unsubscribed']++;
                    continue;
                }
                if ($status === 'active') {
                    $counts['already_active']++;
                    continue;
                }
                if ($groups->subscribe($constituentId, $groupId, 'gaggle_csv')) $counts['subscribed']++;
                else $counts['failed']++;
            }

            $message = sprintf(
                'CSV import complete: %1$d rows, %2$d new people, %3$d matched people, %4$d newly subscribed, %5$d already active, %6$d prior unsubscribes preserved, %7$d invalid rows, %8$d failures.',
                $counts['rows'], $counts['new_people'], $counts['matched_people'], $counts['subscribed'],
                $counts['already_active'], $counts['unsubscribed'], $counts['invalid'], $counts['failed']
            );
            $this->container->get('logger')->info('correspondence', 'Subscriber-group CSV import completed.', [
                'group' => $groupId,
                'rows' => $counts['rows'],
                'subscribed' => $counts['subscribed'],
                'invalid' => $counts['invalid'],
                'failed' => $counts['failed'],
            ]);
            $this->purge($token, $path, $claimOwner);
            $this->redirect($message, $counts['failed'] > 0);
        } catch (\Throwable $e) {
            $this->purge($token, $path, $claimOwner);
            $this->container->get('logger')->warn('correspondence', 'Subscriber-group CSV import failed.', [
                'reason' => bg_substr($e->getMessage(), 0, 180),
            ]);
            $this->redirect($e->getMessage(), true);
        }
    }

    /** @param array<int,string> $headers */
    public function renderMapping(string $token, array $headers, object $group, string $sourceName): void
    {
        $suggestions = $this->suggest($headers);
        ?>
        <div class="card" style="max-width:1000px">
          <h2>Map subscriber CSV</h2>
          <p><strong>Source:</strong> <?php echo esc_html($sourceName); ?>. <strong>Destination:</strong> <?php echo esc_html((string) $group->name); ?>.</p>
          <p>Confirm which CSV column belongs to each contact field. Email address is required. Existing local records keep their current values; the import fills blanks only.</p>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="bg_group_import_commit">
            <input type="hidden" name="import_token" value="<?php echo esc_attr($token); ?>">
            <?php wp_nonce_field('bg_group_import_commit'); ?>
            <table class="widefat striped"><thead><tr><th>Field</th><th>CSV column</th></tr></thead><tbody>
            <?php foreach (self::targets() as $key => $target): ?>
              <tr><th><?php echo esc_html($target['label']); ?><?php if ($key === 'email'): ?> <span aria-label="required" style="color:#b32d2e">*</span><?php endif; ?></th><td>
                <select name="mapping[<?php echo esc_attr($key); ?>]"<?php echo $key === 'email' ? ' required' : ''; ?>>
                  <option value="">(Do not import)</option>
                  <?php foreach ($headers as $column => $header): if ($header === '') continue; ?>
                    <option value="<?php echo (int) $column; ?>" <?php selected((int) ($suggestions[$key] ?? 0), (int) $column); ?>><?php echo esc_html($header); ?></option>
                  <?php endforeach; ?>
                </select>
              </td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php submit_button('Import into ' . (string) $group->name); ?>
          </form>
        </div>
        <?php
    }

    /** @return array<string,mixed>|null */
    public function descriptorForCurrentUser(string $token): ?array
    {
        return $this->descriptor($token);
    }

    public function cleanupScheduled(string $token, string $path): void
    {
        $claimKey = $this->claimKey($token);
        $owner = OptionLease::acquire($claimKey, self::TOKEN_TTL + 15 * MINUTE_IN_SECONDS);
        if ($owner === '') {
            wp_schedule_single_event(time() + 15 * MINUTE_IN_SECONDS, 'bg_group_csv_cleanup', [$token, $path]);
            return;
        }
        $this->purge($token, $path, $owner);
    }

    /** @param array<int,string> $headers @return array<string,int> */
    private function suggest(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $column => $header) $normalized[(int) $column] = self::normalize((string) $header);
        $out = [];
        foreach (self::targets() as $key => $target) {
            foreach ($target['aliases'] as $alias) {
                $match = array_search(self::normalize($alias), $normalized, true);
                if ($match !== false) {
                    $out[$key] = (int) $match;
                    break;
                }
            }
        }
        if (!empty($out['first_name']) || !empty($out['last_name'])) unset($out['full_name']);
        return $out;
    }

    /** @param array<string,mixed> $posted @param array<int,string> $headers @return array<string,int> */
    private function mappingFromRequest(array $posted, array $headers): array
    {
        $mapping = [];
        foreach (self::targets() as $key => $_target) {
            $column = max(0, (int) ($posted[$key] ?? 0));
            $mapping[$key] = $column > 0 && isset($headers[$column]) ? $column : 0;
        }
        return $mapping;
    }

    /** @param array<int,string> $cells @param array<string,int> $mapping @return array<string,string> */
    private function rowData(array $cells, array $mapping): array
    {
        $data = [];
        foreach (['first_name', 'last_name', 'phone', 'address1', 'address2', 'city', 'state', 'postal'] as $field) {
            $data[$field] = sanitize_text_field($this->cell($cells, $mapping, $field));
        }
        $data['notes'] = sanitize_textarea_field($this->cell($cells, $mapping, 'notes'));
        $data['email'] = sanitize_email($this->cell($cells, $mapping, 'email'));
        if ($data['first_name'] === '' && $data['last_name'] === '') {
            [$data['first_name'], $data['last_name']] = $this->splitName(
                sanitize_text_field($this->cell($cells, $mapping, 'full_name'))
            );
        }
        foreach (['first_name' => 100, 'last_name' => 100, 'phone' => 80, 'address1' => 250,
                  'address2' => 250, 'city' => 120, 'state' => 80, 'postal' => 40, 'notes' => 2000] as $field => $limit) {
            $data[$field] = bg_substr($data[$field], 0, $limit);
        }
        return $data;
    }

    /** @param array<int,string> $cells @param array<string,int> $mapping */
    private function cell(array $cells, array $mapping, string $field): string
    {
        $column = (int) ($mapping[$field] ?? 0);
        return $column > 0 ? trim((string) ($cells[$column] ?? '')) : '';
    }

    /** @return array{string,string} */
    private function splitName(string $name): array
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        if ($name === '') return ['', ''];
        if (str_contains($name, ',')) {
            [$last, $first] = array_pad(array_map('trim', explode(',', $name, 2)), 2, '');
            return [$first, $last];
        }
        $parts = preg_split('/\s+/', $name, 2) ?: [];
        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }

    private static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    private function cleanHeader(string $header): string
    {
        if (str_starts_with($header, "\xEF\xBB\xBF")) $header = substr($header, 3);
        return bg_substr(sanitize_text_field($header), 0, 190);
    }

    /** @return array<string,mixed>|null */
    private function descriptor(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $value = get_transient($this->transientKey($token));
        if (!is_array($value) || (int) ($value['user_id'] ?? 0) !== get_current_user_id()) return null;
        if (time() - (int) ($value['created_at'] ?? 0) > self::TOKEN_TTL) return null;
        $path = (string) ($value['path'] ?? '');
        if (!$this->validPath($path) || !is_readable($path)) return null;
        return $value;
    }

    private function transientKey(string $token): string
    {
        return 'bg_group_csv_' . substr(hash('sha256', $token), 0, 40);
    }

    private function claimKey(string $token): string
    {
        return 'bg_group_csv_claim_' . substr(hash('sha256', $token), 0, 36);
    }

    private function validPath(string $path): bool
    {
        if ($path === '' || !preg_match('/^[a-f0-9]{64}\.csv$/', basename($path))) return false;
        $directory = realpath($this->directory());
        $parent = realpath(dirname($path));
        return $directory !== false && $parent !== false && hash_equals($directory, $parent);
    }

    private function purge(string $token, string $path, string $owner): void
    {
        delete_transient($this->transientKey($token));
        if ($this->validPath($path) && is_file($path)) @unlink($path);
        OptionLease::release($this->claimKey($token), $owner);
    }

    private function directory(): string
    {
        return \BosqueGrande\Core\PrivateStorage::directory('bg-private-group-imports');
    }

    private function protectDirectory(string $directory): void
    {
        $files = [
            '.htaccess' => "Deny from all\n",
            'web.config' => '<?xml version="1.0"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>',
            'index.php' => "<?php http_response_code(404); exit;\n",
        ];
        foreach ($files as $name => $body) {
            $target = $directory . '/' . $name;
            if (!file_exists($target)) @file_put_contents($target, $body, LOCK_EX);
        }
    }

    private function removeExpiredFiles(string $directory): void
    {
        foreach ((array) glob($directory . '/*.csv') as $path) {
            if ($this->validPath((string) $path) && filemtime((string) $path) < time() - self::TOKEN_TTL) @unlink((string) $path);
        }
    }

    private function authorize(string $nonce): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer($nonce);
    }

    private function redirect(string $message, bool $error = false): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => 'bosquegrande-groups',
            'bg_notice' => bg_substr(sanitize_text_field($message), 0, 500),
            'bg_error' => $error ? 1 : 0,
        ], admin_url('admin.php')));
        exit;
    }
}
