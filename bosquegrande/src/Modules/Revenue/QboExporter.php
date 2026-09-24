<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Revenue;

use BosqueGrande\Core\Encryption;
use BosqueGrande\Core\Logger;
use BosqueGrande\Modules\Constituents\ConstituentStore;

if (!defined('ABSPATH')) exit;

/**
 * QuickBooks Online Sales Receipt CSV export, absorbed from Black Spruce.
 *
 * A ledger payment becomes one BG-numbered Sales Receipt. A receipt may use
 * several rows only when the administrator deliberately allocates it across
 * QBO classes. Classification is deterministic and fail-closed: a malformed
 * higher-priority rule never falls through to a broader rule, and nothing is
 * inferred from event text, Grant Tags, or a provider call. When a written
 * mapping deliberately leaves Class blank, the separate deterministic QBO
 * donor-region policy supplies one 100% Class from the current linked address.
 */
final class QboExporter
{
    public const SALES_RECEIPT_LIMIT = 100;
    private const CSV_DATA_ROW_LIMIT = 999; // Header + data stays at or below 1,000 rows.
    private const SCAN_PAGE_SIZE = 500;
    private const EXPORTABLE_KINDS = ['donation', 'event_fee', 'sale'];
    private const MAPPING_SCOPES = ['transaction', 'exact', 'route', 'kind'];

    public function __construct(
        private Ledger $ledger,
        private ConstituentStore $store,
        private Encryption $encryption,
        private Logger $logger,
    ) {
    }

    /* ── Mappings ─────────────────────────────────────────────────── */

    /**
     * @param list<array{class:string,bps:int}> $allocations
     */
    public function addMapping(
        string $kind,
        string $value,
        string $account,
        string $item,
        string $class,
        array $allocations = []
    ): bool {
        global $wpdb;
        $data = $this->mappingWriteData($kind, $value, $account, $item, $class, $allocations);
        if ($data === null) return false;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_qbo_mappings WHERE match_kind = %s AND match_value = %s",
            $data['match_kind'], $data['match_value']
        ));
        if ($exists !== null) return false;

        $data['created_at'] = current_time('mysql', true);
        return (bool) $wpdb->insert($wpdb->prefix . 'bg_qbo_mappings', $data);
    }

    /** @param list<array{class:string,bps:int}> $allocations */
    public function updateMapping(
        int $id,
        string $kind,
        string $value,
        string $account,
        string $item,
        string $class,
        array $allocations = []
    ): bool {
        global $wpdb;
        if ($id < 1) return false;
        $data = $this->mappingWriteData($kind, $value, $account, $item, $class, $allocations);
        if ($data === null) return false;

        $present = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_qbo_mappings WHERE id = %d", $id
        ));
        if ($present === null) return false;
        $duplicate = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bg_qbo_mappings
             WHERE match_kind = %s AND match_value = %s AND id != %d",
            $data['match_kind'], $data['match_value'], $id
        ));
        if ($duplicate !== null) return false;

        return $wpdb->update($wpdb->prefix . 'bg_qbo_mappings', $data, ['id' => $id]) !== false;
    }

    public function deleteMapping(int $id): bool
    {
        global $wpdb;
        return $id > 0 && $wpdb->delete($wpdb->prefix . 'bg_qbo_mappings', ['id' => $id], ['%d']) === 1;
    }

    public function mapping(int $id): ?object
    {
        global $wpdb;
        if ($id < 1) return null;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_qbo_mappings WHERE id = %d", $id
        ));
        return $row ?: null;
    }

    /** @return list<object> */
    public function mappings(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}bg_qbo_mappings ORDER BY match_kind ASC, match_value ASC"
        ) ?: [];
    }

    /**
     * Empty means no class or malformed storage. mappingValidationError()
     * distinguishes those cases without allowing malformed data to export.
     *
     * @return list<array{class:string,bps:int}>
     */
    public function allocationsForMapping(object $row): array
    {
        $parsed = $this->parseStoredAllocations($row);
        return $parsed['error'] === null ? $parsed['lines'] : [];
    }

    /** Null means the whole mapping, including allocation storage, is valid. */
    public function mappingValidationError(object $row): ?string
    {
        $kind = (string) ($row->match_kind ?? '');
        $value = (string) ($row->match_value ?? '');
        if (!$this->validMappingKey($kind, $value)) return 'invalid or unsafe mapping key';

        $account = (string) ($row->qbo_account ?? '');
        $item = (string) ($row->qbo_item ?? '');
        if ($item === '' || !$this->validIdentifier($item)) return 'Product/Service is missing or unsafe';
        if ($account !== '' && !$this->validIdentifier($account)) return 'Deposit Account is unsafe';

        return $this->parseStoredAllocations($row)['error'];
    }

    /** @return array{kind:string,value:string,label:string}|null */
    public function mappingKeyForPayment(object $payment, string $scope): ?array
    {
        $scope = strtolower(trim($scope));
        $kind = (string) ($payment->kind ?? '');
        if (!in_array($scope, self::MAPPING_SCOPES, true)
            || !in_array($kind, self::EXPORTABLE_KINDS, true)
        ) return null;

        if ($scope === 'transaction') {
            $id = (int) ($payment->id ?? 0);
            if ($id < 1) return null;
            return ['kind' => 'payment', 'value' => (string) $id, 'label' => 'Only transaction BG-' . $id];
        }
        if ($scope === 'exact') {
            $value = $this->paymentDescription($payment);
            if (!$this->validIdentifier($value)) return null;
            return [
                'kind' => $kind,
                'value' => $value,
                'label' => 'Exact ' . str_replace('_', ' ', $kind) . ' description: ' . $value,
            ];
        }
        if ($scope === 'route') {
            $route = $this->paymentRoute($payment);
            return [
                'kind' => 'route_' . $kind,
                'value' => $route,
                'label' => 'Payment route: ' . $this->routeLabel($route),
            ];
        }
        return [
            'kind' => 'kind_default',
            'value' => $kind,
            'label' => 'Every ' . str_replace('_', ' ', $kind) . ' without a narrower rule',
        ];
    }

    /** A stable, non-fuzzy route derived only from authoritative ledger fields. */
    public function paymentRoute(object $payment): string
    {
        $source = strtolower(trim((string) ($payment->source ?? '')));
        $note = (string) ($payment->note ?? '');
        if ($source === 'stripe_monthly') return 'monthly_stripe';
        if ($source === 'form') return 'website_form';
        if ($source === 'event' || $source === 'event-reconcile') return 'event_card';
        if ($source === 'organizer_app' && str_starts_with($note, 'Cash')) return 'event_cash';
        if ($source === 'organizer_app' && str_starts_with($note, 'Check')) return 'event_check';
        $safeSource = sanitize_key($source);
        return 'source_' . ($safeSource !== '' ? $safeSource : 'unknown');
    }

    /* ── Classification ───────────────────────────────────────────── */

    /** @return array<string,mixed>|null */
    public function classify(string $kind, string $value): ?array
    {
        $row = $this->mappingByKey($kind, $value);
        return $row ? $this->classificationFromRow($row, 'exact') : null;
    }

    /** @return array<string,mixed> */
    private function classificationForPayment(object $payment): array
    {
        foreach (self::MAPPING_SCOPES as $scope) {
            $key = $this->mappingKeyForPayment($payment, $scope);
            if ($key === null) {
                // Unsafe notes remain safe free descriptions under a route or
                // default. An unsafe legacy exact row, however, fails closed.
                if ($scope === 'exact') {
                    $legacy = $this->mappingByKey(
                        (string) ($payment->kind ?? ''),
                        $this->paymentDescription($payment)
                    );
                    if ($legacy) {
                        return [
                            'status' => 'invalid',
                            'error' => 'an unsafe exact rule exists and must be removed',
                            'mapping_id' => (int) ($legacy->id ?? 0),
                        ];
                    }
                    continue;
                }
                return ['status' => 'invalid', 'error' => 'payment cannot form a deterministic mapping key'];
            }

            $row = $this->mappingByKey($key['kind'], $key['value']);
            if (!$row) continue;
            $classified = $this->classificationFromRow($row, $scope);
            if (($classified['valid'] ?? false) !== true) {
                return [
                    'status' => 'invalid',
                    'error' => (string) ($classified['error'] ?? 'mapping is invalid'),
                    'mapping_id' => (int) ($row->id ?? 0),
                ];
            }
            $classified['status'] = 'ok';
            return $classified;
        }
        return ['status' => 'unmapped'];
    }

    /** @return array<string,mixed> */
    private function classificationFromRow(object $row, string $source): array
    {
        $error = $this->mappingValidationError($row);
        if ($error !== null) {
            return [
                'valid' => false,
                'error' => $error,
                'source' => $source,
                'mapping_id' => (int) ($row->id ?? 0),
            ];
        }
        return [
            'valid' => true,
            'account' => (string) $row->qbo_account,
            'item' => (string) $row->qbo_item,
            'class' => (string) ($row->qbo_class ?? ''),
            'allocations' => $this->allocationsForMapping($row),
            'source' => $source,
            'mapping_id' => (int) ($row->id ?? 0),
        ];
    }

    private function mappingByKey(string $kind, string $value): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bg_qbo_mappings WHERE match_kind = %s AND match_value = %s",
            $kind, $value
        ));
        return $row ?: null;
    }

    /* ── Review queue ─────────────────────────────────────────────── */

    public function queueReview(int $paymentId, string $reason): void
    {
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, resolved, disposition FROM {$wpdb->prefix}bg_qbo_review
             WHERE payment_id = %d ORDER BY id DESC LIMIT 1", $paymentId
        ));
        if ($existing) {
            if ((string) $existing->disposition !== '' || (int) $existing->resolved === 0) return;
            $wpdb->update($wpdb->prefix . 'bg_qbo_review', [
                'reason' => bg_substr($reason, 0, 190),
                'resolved' => 0,
                'disposition' => '',
            ], ['id' => (int) $existing->id]);
            return;
        }
        $wpdb->insert($wpdb->prefix . 'bg_qbo_review', [
            'payment_id' => $paymentId,
            'reason' => bg_substr($reason, 0, 190),
            'resolved' => 0,
            'disposition' => '',
            'created_at' => current_time('mysql', true),
        ]);
    }

    /** Keep test generation free of QBO review/export/payment-state writes. */
    private function reviewPayment(int $paymentId, string $reason, bool $write): void
    {
        if ($write) $this->queueReview($paymentId, $reason);
    }

    /** @return list<object> */
    public function openReviews(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT r.* FROM {$wpdb->prefix}bg_qbo_review r
             INNER JOIN {$wpdb->prefix}bg_payments p ON p.id = r.payment_id
             WHERE r.resolved = 0 AND p.exported_at IS NULL
             ORDER BY r.id ASC"
        ) ?: [];
    }

    public function resolveReview(int $reviewId): bool
    {
        global $wpdb;
        if ($reviewId < 1) return false;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}bg_qbo_review r
             INNER JOIN {$wpdb->prefix}bg_payments p ON p.id = r.payment_id
             SET r.resolved = 1, r.disposition = 'handled_in_qbo'
             WHERE r.id = %d AND r.resolved = 0 AND p.exported_at IS NULL",
            $reviewId
        ));
        return $updated === 1;
    }

    /** @param list<int> $paymentIds */
    public function closeReviewsForExport(array $paymentIds): bool
    {
        global $wpdb;
        $paymentIds = array_values(array_unique(array_filter(
            array_map('intval', $paymentIds),
            static fn(int $id): bool => $id > 0
        )));
        if (!$paymentIds) return true;
        $placeholders = implode(',', array_fill(0, count($paymentIds), '%d'));
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->prefix}bg_qbo_review
             SET resolved = 1, disposition = 'exported'
             WHERE resolved = 0 AND payment_id IN ({$placeholders})",
            ...$paymentIds
        );
        return $wpdb->query($sql) !== false;
    }

    /* ── The export ───────────────────────────────────────────────── */

    /**
     * @return array{header:list<string>,rows:list<list<string>>,ids:list<int>,reviewed:int}
     * @param bool $queueReviews False builds a QBO-state-read-only test worksheet.
     */
    public function prepare(bool $queueReviews = true): array
    {
        $header = [
            'SalesReceiptNo', 'Customer', 'SalesReceiptDate', 'ProductService',
            'Description', 'Quantity', 'Rate', 'Amount', 'Class',
            'DepositAccount', 'PaymentMethod', 'PaymentReference',
        ];
        $rows = [];
        $ids = [];
        $seen = [];
        $reviewed = 0;

        foreach ($this->pendingPayments() as $payment) {
            if (count($ids) >= self::SALES_RECEIPT_LIMIT) break;
            $paymentId = (int) ($payment->id ?? 0);
            if ($paymentId < 1 || isset($seen[$paymentId])) continue;
            $seen[$paymentId] = true;

            if ((string) ($payment->kind ?? '') === 'refund') {
                $this->reviewPayment(
                    $paymentId,
                    'refund: record with the supported QuickBooks refund or credit workflow',
                    $queueReviews
                );
                $reviewed++;
                continue;
            }
            if (!in_array((string) ($payment->kind ?? ''), self::EXPORTABLE_KINDS, true)) {
                $this->reviewPayment(
                    $paymentId,
                    'unsupported ledger kind for a QuickBooks Sales Receipt',
                    $queueReviews
                );
                $reviewed++;
                continue;
            }
            if ((int) ($payment->amount_cents ?? 0) < 1
                || !\BosqueGrande\Core\Money::matches((string) ($payment->currency ?? ''))
            ) {
                $this->reviewPayment(
                    $paymentId,
                    'amount or currency is invalid for the Sales Receipt export',
                    $queueReviews
                );
                $reviewed++;
                continue;
            }

            $constituentId = (int) ($payment->constituent_id ?? 0);
            $constituentRecord = $constituentId > 0 ? $this->store->get($constituentId) : null;
            $customer = $this->customerName(is_array($constituentRecord) ? $constituentRecord : []);
            if ($customer === '' || !$this->validIdentifier($customer)) {
                $this->reviewPayment(
                    $paymentId,
                    'customer name is missing or unsafe for QuickBooks matching',
                    $queueReviews
                );
                $reviewed++;
                continue;
            }

            $classified = $this->classificationForPayment($payment);
            if (($classified['status'] ?? '') !== 'ok') {
                if (($classified['status'] ?? '') === 'invalid') {
                    $mappingId = (int) ($classified['mapping_id'] ?? 0);
                    $reason = 'invalid QBO mapping' . ($mappingId > 0 ? ' #' . $mappingId : '')
                        . ': ' . (string) ($classified['error'] ?? 'validation failed');
                } else {
                    $reason = 'no Sales Receipt mapping matched transaction, exact description, route, or kind default';
                }
                $this->reviewPayment($paymentId, $reason, $queueReviews);
                $reviewed++;
                continue;
            }

            $date = get_date_from_gmt((string) ($payment->created_at ?? ''), 'm/d/Y');
            if (!preg_match('/^(?:0[1-9]|1[0-2])\/(?:0[1-9]|[12]\d|3[01])\/\d{4}$/', (string) $date)) {
                $this->reviewPayment(
                    $paymentId,
                    'ledger date is invalid for the business-timezone Sales Receipt date',
                    $queueReviews
                );
                $reviewed++;
                continue;
            }

            $reference = $this->paymentReference($payment);
            if ($reference !== '' && !$this->validIdentifier($reference)) {
                $this->reviewPayment(
                    $paymentId,
                    'payment reference is unsafe for QuickBooks',
                    $queueReviews
                );
                $reviewed++;
                continue;
            }

            /** @var list<array{class:string,bps:int}> $allocations */
            $allocations = $classified['allocations'];
            if (!$allocations) {
                $region = QboTransactionRegion::forRecord(
                    is_array($constituentRecord) ? $constituentRecord : []
                );
                $regionClass = (string) ($region['class'] ?? '');
                if ($regionClass === '' || !$this->validIdentifier($regionClass)) {
                    $this->reviewPayment(
                        $paymentId,
                        'QBO donor region needs review before a Class can be assigned',
                        $queueReviews
                    );
                    $reviewed++;
                    continue;
                }
                $allocations = [['class' => $regionClass, 'bps' => 10000]];
            }
            $split = $this->splitCents((int) $payment->amount_cents, $allocations);
            if ($split === null) {
                $this->reviewPayment(
                    $paymentId,
                    'class allocation cannot produce a positive, exact-cent line for every selected class',
                    $queueReviews
                );
                $reviewed++;
                continue;
            }
            if (count($rows) + count($split) > self::CSV_DATA_ROW_LIMIT) break;

            $description = ConstituentStore::safeCsv($this->paymentDescription($payment));
            $method = $this->paymentMethod($payment);
            foreach ($split as $index => $lineCents) {
                $lineAmount = number_format($lineCents / 100, 2, '.', '');
                $rows[] = [
                    'BG-' . $paymentId,
                    $customer,
                    $date,
                    (string) $classified['item'],
                    $description,
                    '1',
                    $lineAmount,
                    $lineAmount,
                    (string) $allocations[$index]['class'],
                    (string) $classified['account'],
                    $method,
                    $reference,
                ];
            }
            $ids[] = $paymentId;
        }

        return ['header' => $header, 'rows' => $rows, 'ids' => $ids, 'reviewed' => $reviewed];
    }

    /**
     * Page by immutable ledger ID so old review rows cannot hide later valid
     * receipts. Each page is bounded in memory; iteration stops as soon as the
     * receipt/CSV cap is reached or the pending ledger is exhausted.
     *
     * @return \Generator<int,object>
     */
    private function pendingPayments(): \Generator
    {
        $afterId = 0;
        while (true) {
            $page = $this->ledger->unexportedAfter($afterId, self::SCAN_PAGE_SIZE);
            if (!$page) return;
            foreach ($page as $payment) {
                $paymentId = (int) ($payment->id ?? 0);
                if ($paymentId <= $afterId) continue;
                $afterId = $paymentId;
                yield $payment;
            }
            if (count($page) < self::SCAN_PAGE_SIZE) return;
        }
    }

    public function recordExport(string $filename, int $receipts, string $csv): int
    {
        global $wpdb;
        if ($filename === '' || $receipts < 1 || $csv === '' || !$this->encryption->available()) {
            $this->logger->error('revenue', 'QuickBooks Sales Receipt export could not be retained securely.', [
                'receipts' => $receipts,
                'encryption_available' => $this->encryption->available(),
            ]);
            return 0;
        }
        $ok = $wpdb->insert($wpdb->prefix . 'bg_qbo_exports', [
            'filename' => bg_substr($filename, 0, 190),
            // Legacy column name retained for an additive, data-safe upgrade.
            'invoices' => $receipts,
            'payload_enc' => '',
            'payload_sha256' => hash('sha256', $csv),
            'created_at' => current_time('mysql', true),
        ]);
        if (!$ok) {
            $this->logger->error('revenue', 'QuickBooks Sales Receipt export ledger row could not be created.', [
                'receipts' => $receipts,
            ]);
            return 0;
        }
        $exportId = (int) $wpdb->insert_id;
        $cipher = $this->encryption->encrypt($csv, self::exportContext($exportId));
        $verified = str_starts_with($cipher, 'bg2:')
            ? $this->encryption->decryptOrNull($cipher, self::exportContext($exportId))
            : null;
        if (!is_string($verified) || !hash_equals($csv, $verified) || $wpdb->update(
            $wpdb->prefix . 'bg_qbo_exports',
            ['payload_enc' => $cipher],
            ['id' => $exportId],
            ['%s'],
            ['%d']
        ) !== 1) {
            $this->logger->error('revenue', 'QuickBooks Sales Receipt export copy could not be encrypted.', [
                'export_id' => $exportId,
            ]);
            return 0;
        }
        $this->logger->info('revenue', 'QuickBooks Sales Receipt export produced.', ['receipts' => $receipts]);
        return $exportId;
    }

    /** @return list<object> */
    public function recentExports(int $limit = 10): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, filename, invoices, invoices AS receipts, created_at,
                    CASE WHEN payload_enc IS NULL OR payload_enc = '' THEN 0 ELSE 1 END AS downloadable
             FROM {$wpdb->prefix}bg_qbo_exports ORDER BY id DESC LIMIT %d",
            max(1, min(50, $limit))
        )) ?: [];
    }

    /** @return array{filename:string,csv:string}|null */
    public function exportCopy(int $exportId): ?array
    {
        global $wpdb;
        if ($exportId < 1) return null;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT filename, payload_enc, payload_sha256 FROM {$wpdb->prefix}bg_qbo_exports WHERE id = %d",
            $exportId
        ));
        if (!$row || !str_starts_with((string) $row->payload_enc, 'bg2:')) return null;
        $csv = $this->encryption->decryptOrNull((string) $row->payload_enc, self::exportContext($exportId));
        if (!is_string($csv) || $csv === '' || !hash_equals((string) $row->payload_sha256, hash('sha256', $csv))) {
            $this->logger->error('revenue', 'QuickBooks retained Sales Receipt export failed integrity verification.', [
                'export_id' => $exportId,
            ]);
            return null;
        }
        return ['filename' => (string) $row->filename, 'csv' => $csv];
    }

    private static function exportContext(int $exportId): string
    {
        return 'qbo-export|' . $exportId;
    }

    /* ── Validation and allocation helpers ───────────────────────── */

    /**
     * @param list<array{class:string,bps:int}> $allocations
     * @return array<string,mixed>|null
     */
    private function mappingWriteData(
        string $kind,
        string $value,
        string $account,
        string $item,
        string $class,
        array $allocations
    ): ?array {
        $kind = trim($kind);
        $value = trim($value);
        $account = trim($account);
        $item = trim($item);
        $class = trim($class);
        if (!$this->validMappingKey($kind, $value)
            || $item === ''
            || !$this->validIdentifier($item)
            || ($account !== '' && !$this->validIdentifier($account))
            || ($class !== '' && !$this->validIdentifier($class))
            || ($class !== '' && $allocations)
        ) return null;

        $legacyClass = $class;
        $allocationJson = null;
        if ($allocations) {
            $lines = $this->validateAllocationLines($allocations, 1, 10);
            if ($lines === null) return null;
            if (count($lines) === 1) {
                $legacyClass = $lines[0]['class'];
            } else {
                $encoded = wp_json_encode(['version' => 1, 'lines' => $lines]);
                if (!is_string($encoded) || $encoded === '') return null;
                $legacyClass = '';
                $allocationJson = $encoded;
            }
        }

        return [
            'match_kind' => $kind,
            'match_value' => $value,
            'qbo_account' => $account,
            'qbo_item' => $item,
            'qbo_class' => $legacyClass,
            'qbo_class_allocations' => $allocationJson,
        ];
    }

    /** @return array{error:?string,lines:list<array{class:string,bps:int}>} */
    private function parseStoredAllocations(object $row): array
    {
        $legacyClass = (string) ($row->qbo_class ?? '');
        $rawJson = $row->qbo_class_allocations ?? null;
        $json = $rawJson === null ? '' : (string) $rawJson;

        if ($legacyClass !== '' && $json !== '') {
            return ['error' => 'legacy class and allocation JSON are both set', 'lines' => []];
        }
        if ($legacyClass !== '') {
            if (!$this->validIdentifier($legacyClass)) return ['error' => 'class is unsafe', 'lines' => []];
            return ['error' => null, 'lines' => [['class' => $legacyClass, 'bps' => 10000]]];
        }
        if ($json === '') return ['error' => null, 'lines' => []];
        if ($json !== trim($json)) return ['error' => 'class allocation JSON is not canonical', 'lines' => []];

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'class allocation JSON is invalid', 'lines' => []];
        }
        $topKeys = array_keys($decoded);
        sort($topKeys, SORT_STRING);
        if ($topKeys !== ['lines', 'version']
            || ($decoded['version'] ?? null) !== 1
            || !is_array($decoded['lines'] ?? null)
            || !$this->isList($decoded['lines'])
        ) return ['error' => 'class allocation JSON has the wrong shape or version', 'lines' => []];

        $lines = $this->validateAllocationLines($decoded['lines'], 2, 10);
        if ($lines === null) {
            return ['error' => 'class allocations must be 2–10 unique positive lines totaling 100%', 'lines' => []];
        }
        $canonical = wp_json_encode(['version' => 1, 'lines' => $lines]);
        if (!is_string($canonical) || !hash_equals($canonical, $json)) {
            return ['error' => 'class allocation JSON is not canonical', 'lines' => []];
        }
        return ['error' => null, 'lines' => $lines];
    }

    /**
     * @param array<mixed> $input
     * @return list<array{class:string,bps:int}>|null
     */
    private function validateAllocationLines(array $input, int $minimum, int $maximum): ?array
    {
        if (!$this->isList($input) || count($input) < $minimum || count($input) > $maximum) return null;
        $lines = [];
        $seen = [];
        $total = 0;
        foreach ($input as $line) {
            if (!is_array($line)) return null;
            $keys = array_keys($line);
            sort($keys, SORT_STRING);
            if ($keys !== ['bps', 'class'] || !is_string($line['class'] ?? null) || !is_int($line['bps'] ?? null)) {
                return null;
            }
            $class = (string) $line['class'];
            $bps = (int) $line['bps'];
            if (!$this->validIdentifier($class) || $bps < 1 || $bps > 10000) return null;
            $folded = function_exists('mb_strtolower') ? mb_strtolower($class, 'UTF-8') : strtolower($class);
            if (isset($seen[$folded])) return null;
            $seen[$folded] = true;
            $total += $bps;
            $lines[] = ['class' => $class, 'bps' => $bps];
        }
        return $total === 10000 ? $lines : null;
    }

    private function validMappingKey(string $kind, string $value): bool
    {
        if ($kind !== trim($kind) || $value !== trim($value) || !$this->validIdentifier($value)) return false;
        if ($kind === 'payment') {
            return ctype_digit($value) && (int) $value > 0 && (string) (int) $value === $value;
        }
        if ($kind === 'kind_default') return in_array($value, self::EXPORTABLE_KINDS, true);
        if (in_array($kind, self::EXPORTABLE_KINDS, true)) return true;
        if (!preg_match('/^route_(donation|event_fee|sale)$/', $kind)) return false;
        return (bool) preg_match(
            '/^(?:monthly_stripe|website_form|event_card|event_cash|event_check|source_[a-z0-9_-]+)$/',
            $value
        );
    }

    /** Identifiers are written verbatim; unsafe spreadsheet tokens never export. */
    private function validIdentifier(string $value, int $maximum = 190): bool
    {
        if ($value === '' || $value !== trim($value)) return false;
        if ((function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8'))
            || preg_match('//u', $value) !== 1
        ) return false;
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length < 1 || $length > $maximum) return false;
        if (preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', $value) !== 0) return false;
        if (preg_match('/^[\s\p{Z}\x{FEFF}]|[\s\p{Z}\x{FEFF}]$/u', $value) !== 0) return false;
        return preg_match('/^[\s\p{Z}\x{FEFF}]*[=+\-@]/u', $value) === 0;
    }

    /**
     * @param list<array{class:string,bps:int}> $allocations
     * @return list<int>|null
     */
    private function splitCents(int $amountCents, array $allocations): ?array
    {
        if ($amountCents < 1 || !$allocations) return null;
        $lines = $this->validateAllocationLines($allocations, 1, 10);
        if ($lines === null) return null;

        $shares = [];
        $remainders = [];
        $allocated = 0;
        foreach ($lines as $index => $line) {
            $numerator = $amountCents * $line['bps'];
            $shares[$index] = intdiv($numerator, 10000);
            $remainders[$index] = $numerator % 10000;
            $allocated += $shares[$index];
        }
        $remaining = $amountCents - $allocated;
        if ($remaining < 0 || $remaining > count($shares)) return null;
        $order = array_keys($shares);
        usort($order, static function (int $left, int $right) use ($remainders): int {
            if ($remainders[$left] === $remainders[$right]) return $left <=> $right;
            return $remainders[$right] <=> $remainders[$left];
        });
        for ($i = 0; $i < $remaining; $i++) $shares[$order[$i]]++;
        ksort($shares, SORT_NUMERIC);
        $shares = array_values($shares);
        if (array_sum($shares) !== $amountCents) return null;
        foreach ($shares as $share) {
            if ($share < 1) return null;
        }
        return $shares;
    }

    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    private function paymentDescription(object $payment): string
    {
        $note = trim((string) ($payment->note ?? ''));
        return $note !== '' ? $note : ucfirst((string) ($payment->kind ?? ''));
    }

    private function paymentMethod(object $payment): string
    {
        $source = strtolower(trim((string) ($payment->source ?? '')));
        $note = (string) ($payment->note ?? '');
        if ($source === 'organizer_app' && str_starts_with($note, 'Cash')) return 'Cash';
        if ($source === 'organizer_app' && str_starts_with($note, 'Check')) return 'Check';
        if ((string) ($payment->stripe_payment_intent ?? '') !== ''
            || in_array($source, ['stripe_monthly', 'form', 'event', 'event-reconcile'], true)
        ) return 'Stripe';
        return '';
    }

    private function paymentReference(object $payment): string
    {
        $intent = trim((string) ($payment->stripe_payment_intent ?? ''));
        return $intent !== '' ? $intent : trim((string) ($payment->external_key ?? ''));
    }

    private function routeLabel(string $route): string
    {
        return match ($route) {
            'monthly_stripe' => 'Stripe monthly donation',
            'website_form' => 'website form',
            'event_card' => 'event card payment',
            'event_cash' => 'event cash',
            'event_check' => 'event check',
            default => str_replace(['source_', '_', '-'], ['', ' ', ' '], $route),
        };
    }

    /** @param array<string,mixed> $record QuickBooks matches "Last, First"; email is never a fallback. */
    private function customerName(array $record): string
    {
        if (!$record) return '';
        $last = trim((string) ($record['last_name'] ?? ''));
        $first = trim((string) ($record['first_name'] ?? ''));
        if ($last !== '' && $first !== '') return $last . ', ' . $first;
        return $last !== '' ? $last : $first;
    }
}
