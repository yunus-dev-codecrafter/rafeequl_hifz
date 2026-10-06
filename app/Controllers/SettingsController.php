<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Request;
use App\Response;
use App\Services\SettingsService;
use App\Validators\UpdateSettingsValidator;

/**
 * User preferences endpoints (Prompt 18): read and partial-update only.
 */
final class SettingsController extends Controller
{
    private SettingsService $settings;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->settings = new SettingsService();
    }

    public function show(array $params = []): Response
    {
        $user = $this->requireUser();

        return $this->success(['settings' => $this->settings->show($user->id())]);
    }

    public function update(array $params = []): Response
    {
        $user = $this->requireUser();
        $data = $this->validate(new UpdateSettingsValidator());

        return $this->success(['settings' => $this->settings->update($user->id(), $data)]);
    }
}
