<?php

namespace Lkn\BBPix\App\Pix\Services;

use DateTimeImmutable;
use Lkn\BBPix\App\Pix\Repositories\AuthRepository;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Logger;
use WHMCS\Database\Capsule;
use Throwable;

final class DecisionService
{
    public const MANUAL_TRADICIONAL = 'MANUAL_TRADICIONAL';

    public const COBR_AUTOMATICO = 'COBR_AUTOMATICO';

    public const JORNADA4 = 'JORNADA4';

    private AuthRepository $authRepository;

    public function __construct(?AuthRepository $authRepository = null)
    {
        $this->authRepository = $authRepository ?? new AuthRepository();
    }

    public function evaluate(
        string $origemFatura,
        int $clientId,
        int $dueDay,
        string $dueDate,
        int $currentInvoiceId = 0
    ): string
    {
        if (!Config::setting('enable_pix_automatic')) {
            return self::MANUAL_TRADICIONAL;
        }

        try {
            $origemFatura = strtoupper(trim($origemFatura));
            $dueDate = $this->normalizeDate($dueDate);

            if ($dueDate !== '' && $this->isDateBeforeToday($dueDate)) {
                Logger::log(
                    'DecisionService: automatic_blocked_overdue',
                    [
                        'origemFatura' => $origemFatura,
                        'clientId' => $clientId,
                        'dueDay' => $dueDay,
                        'dueDate' => $dueDate,
                        'currentInvoiceId' => $currentInvoiceId
                    ]
                );

                return self::MANUAL_TRADICIONAL;
            }

            if ($this->hasDueDayLockedByScheduledInvoice($clientId, $dueDay, $currentInvoiceId)) {
                Logger::log(
                    'DecisionService: manual_due_day_locked',
                    [
                        'origemFatura' => $origemFatura,
                        'clientId' => $clientId,
                        'dueDay' => $dueDay,
                        'currentInvoiceId' => $currentInvoiceId
                    ]
                );

                return self::MANUAL_TRADICIONAL;
            }

            $approvedAuthResponse = $this->authRepository->findApprovedByClientAndDueDay($clientId, $dueDay);

            if (!is_array($approvedAuthResponse) || !($approvedAuthResponse['success'] ?? false)) {
                Logger::log(
                    'Falha ao decidir fluxo do Pix Automático',
                    [
                        'origemFatura' => $origemFatura,
                        'clientId' => $clientId,
                        'dueDay' => $dueDay
                    ],
                    $approvedAuthResponse
                );

                return self::MANUAL_TRADICIONAL;
            }

            $hasApprovedAuth = !empty($approvedAuthResponse['data']['auth']);

            if ($origemFatura === 'NOVO_PEDIDO' && $hasApprovedAuth) {
                return self::MANUAL_TRADICIONAL;
            }

            if ($origemFatura === 'CRON_RENOVACAO' && $hasApprovedAuth) {
                Logger::log(
                    'DecisionService: automatic_allowed',
                    [
                        'origemFatura' => $origemFatura,
                        'clientId' => $clientId,
                        'dueDay' => $dueDay,
                        'currentInvoiceId' => $currentInvoiceId
                    ]
                );

                return self::COBR_AUTOMATICO;
            }

            if (in_array($origemFatura, ['NOVO_PEDIDO', 'CRON_RENOVACAO'], true)) {
                return self::JORNADA4;
            }

            return self::MANUAL_TRADICIONAL;
        } catch (Throwable $th) {
            Logger::log(
                'Erro ao decidir fluxo do Pix Automático',
                [
                    'origemFatura' => $origemFatura,
                    'clientId' => $clientId,
                    'dueDay' => $dueDay
                ],
                ['error' => $th->getMessage()]
            );

            return self::MANUAL_TRADICIONAL;
        }
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);

        if ($date === '') {
            return '';
        }

        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d', $timestamp);
    }

    private function isDateBeforeToday(string $date): bool
    {
        $dateObj = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        $todayObj = new DateTimeImmutable('today');

        if ($dateObj === false) {
            return false;
        }

        return $dateObj < $todayObj;
    }

    private function hasDueDayLockedByScheduledInvoice(int $clientId, int $dueDay, int $currentInvoiceId): bool
    {
        try {
            $query = Capsule::table('tblinvoices')
                ->join('tblaccounts', 'tblaccounts.invoiceid', '=', 'tblinvoices.id')
                ->where('tblinvoices.userid', $clientId)
                ->where('tblinvoices.status', 'Unpaid')
                ->where('tblinvoices.paymentmethod', 'lknbbpix')
                ->whereRaw('DAY(tblinvoices.duedate) = ?', [$dueDay])
                ->where('tblaccounts.gateway', 'lknbbpix')
                ->where('tblaccounts.transid', 'like', 'AGENDADAx%');

            if ($currentInvoiceId > 0) {
                $query->where('tblinvoices.id', '!=', $currentInvoiceId);
            }

            return $query->exists();
        } catch (Throwable $th) {
            Logger::log(
                'DecisionService: falha ao validar bloqueio por due_day',
                [
                    'clientId' => $clientId,
                    'dueDay' => $dueDay,
                    'currentInvoiceId' => $currentInvoiceId
                ],
                ['error' => $th->getMessage()]
            );

            return false;
        }
    }
}