<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Helpers\Config;
use App\Repositories\AccountRepository;
use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;

/**
 * Privacy / data controls (Prompts 18 & 23): personal-data export (JSON
 * and sectioned CSV) and account deletion.
 *
 * Deletion is the schema's designed soft delete: users.status →
 * 'deleted', identity anonymized (email freed + display name cleared)
 * and every session revoked. The Hifz history stays attached to the
 * tombstone row in pseudonymous form — no personal identifier remains.
 * The account can never sign in again (status check + missing email +
 * no sessions).
 */
final class AccountService
{
    private AccountRepository $accounts;
    private UserRepository $users;
    private SessionRepository $sessions;

    public function __construct(
        ?AccountRepository $accounts = null,
        ?UserRepository $users = null,
        ?SessionRepository $sessions = null,
    ) {
        $this->accounts = $accounts ?? new AccountRepository();
        $this->users = $users ?? new UserRepository();
        $this->sessions = $sessions ?? new SessionRepository();
    }

    /** Full personal-data export; never includes credentials or tokens. */
    public function export(int $userId): array
    {
        $data = $this->accounts->export($userId);
        if ($data['profile'] === null) {
            throw new NotFoundException('Account not found');
        }

        return [
            'exported_at' => gmdate('c'),
            'format' => 'rafeequl-hifz-export-v1',
            ...$data,
        ];
    }

    /**
     * Sectioned CSV rendering of the same export arrays (Prompt 23).
     *
     * One file, one section per dataset: a "# dataset: <key>" marker line,
     * then the column header row, then the rows (same order as the JSON
     * export), sections separated by a blank line. Every dataset key in the
     * JSON export becomes a section — a NULL single-row dataset (or an empty
     * table) emits just its marker. UTF-8 with BOM so spreadsheet apps detect
     * Arabic text. Values are normalized for round-tripping: NULL → empty
     * cell, booleans → 0/1.
     *
     * @throws ValidationException unknown dataset (field: dataset)
     */
    public function exportCsv(int $userId, ?string $dataset = null): string
    {
        $export = $this->export($userId);

        /** @var array<string, array<mixed>> $datasets */
        $datasets = [];
        foreach ($export as $key => $value) {
            if ($key === 'exported_at' || $key === 'format') {
                continue; // envelope metadata, not a dataset
            }
            $datasets[$key] = is_array($value) ? $value : [];
        }

        if ($dataset !== null && !array_key_exists($dataset, $datasets)) {
            throw ValidationException::withErrors([
                ['field' => 'dataset', 'message' => 'Unknown export dataset'],
            ]);
        }

        $sections = [];
        foreach ($datasets as $key => $value) {
            if ($dataset !== null && $key !== $dataset) {
                continue;
            }
            // Single-row datasets are assoc arrays; list datasets are already
            // rows. An empty dataset emits its marker only (no header — the
            // column set is unknowable without a row).
            $sections[$key] = ($value === [] || array_is_list($value)) ? $value : [$value];
        }

        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('temp stream unavailable');
        }
        fwrite($stream, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        $last = array_key_last($sections);
        foreach ($sections as $key => $rows) {
            fputcsv($stream, ['# dataset: ' . $key]);
            $first = reset($rows);
            if (is_array($first)) {
                fputcsv($stream, array_keys($first));
                foreach ($rows as $row) {
                    fputcsv($stream, array_map(self::csvValue(...), $row));
                }
            }
            if ($key !== $last) {
                fwrite($stream, "\n");
            }
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }

    /** CSV cell normalization: NULL → empty, bool → 0/1, everything else scalar. */
    private static function csvValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $cell = is_scalar($value)
            ? (string) $value
            : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // CSV formula injection (Prompt 24): a leading =, +, -, @, tab or CR
        // makes spreadsheet apps evaluate the cell as a formula/ hyperlink.
        // Prefix a single quote (OWASP guidance). Only the CSV rendering is
        // affected — the JSON export stays canonical and untouched.
        if ($cell !== '' && preg_match('/^[=+\-@\t\r]/', $cell) === 1) {
            return "'" . $cell;
        }

        return $cell;
    }

    /**
     * Deletes the account after re-verifying the password (Prompt 18).
     *
     * @throws ValidationException wrong password (field: password)
     */
    public function delete(int $userId, string $password): void
    {
        $auth = $this->users->findAuth($userId);
        if ($auth === null) {
            throw new NotFoundException('Account not found');
        }

        if (!password_verify($password, (string) $auth['password_hash'])) {
            $this->delay();
            throw ValidationException::withErrors([
                ['field' => 'password', 'message' => 'Password is incorrect'],
            ]);
        }

        Database::transaction(function () use ($userId): void {
            $this->users->softDelete($userId);
            $this->sessions->deleteAllForUser($userId);
        });
    }

    /** Equalizes timing for wrong passwords (same posture as AuthService). */
    private function delay(): void
    {
        $microseconds = (int) Config::get('auth', 'failure_delay_microseconds', 200000);
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }
}
