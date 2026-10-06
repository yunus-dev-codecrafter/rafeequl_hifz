<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Request;
use App\Response;
use App\Services\AccountService;
use App\Validators\DeleteAccountValidator;

/**
 * Privacy / data controls (Prompts 18 & 23): export (JSON + CSV) and delete.
 */
final class AccountController extends Controller
{
    private AccountService $account;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->account = new AccountService();
    }

    /**
     * Personal-data export (secrets are excluded): JSON envelope by
     * default, raw sectioned CSV via ?format=csv (Prompt 23).
     * ?dataset=<key> narrows the CSV to one section.
     */
    public function export(array $params = []): Response
    {
        $user = $this->requireUser();

        $format = $this->query('format', 'json');
        if ($format !== 'json' && $format !== 'csv') {
            throw ValidationException::withErrors([
                ['field' => 'format', 'message' => 'Format must be json or csv'],
            ]);
        }

        $dataset = $this->query('dataset');
        $dataset = ($dataset === null || $dataset === '') ? null : (string) $dataset;
        if ($format === 'json' && $dataset !== null) {
            throw ValidationException::withErrors([
                ['field' => 'dataset', 'message' => 'dataset only applies to format=csv'],
            ]);
        }

        if ($format === 'csv') {
            return Response::csv($this->account->exportCsv($user->id(), $dataset))
                ->withHeader('Content-Disposition', 'attachment; filename="rafeequl-hifz-export.csv"');
        }

        return $this->success($this->account->export($user->id()))
            ->withHeader('Content-Disposition', 'attachment; filename="rafeequl-hifz-export.json"');
    }

    /** Password-confirmed soft delete: anonymize, revoke, sign out. */
    public function delete(array $params = []): Response
    {
        $user = $this->requireUser();
        $data = $this->validate(new DeleteAccountValidator());

        $this->account->delete($user->id(), (string) $data['password']);

        return $this->success(['message' => 'Account deleted'])
            ->withoutCookie($this->sessionCookieName());
    }
}
