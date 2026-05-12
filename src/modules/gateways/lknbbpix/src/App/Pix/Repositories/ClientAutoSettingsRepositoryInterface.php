<?php

namespace Lkn\BBPix\App\Pix\Repositories;

interface ClientAutoSettingsRepositoryInterface
{
    public function isEnabledForClient(int $clientId): bool;
}
