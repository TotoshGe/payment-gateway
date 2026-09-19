<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Api\CreateDepositRequestDto;
use App\Entity\DepositRequest;
use App\Panel\BinanceTest\BinanceTestPanel;
use App\Enum\PaymentRequestStatus;
use App\Repository\DepositRequestRepository;
use App\Service\DepositRequestService;
use App\Service\Exception\PanelNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * See ARCHITECTURE.md section 3.1. Idempotent by external_reference: a
 * retried POST with the same reference returns the existing request (200)
 * instead of creating a second one / reserving a second address.
 */
#[Route('/api/v1/deposits')]
final class DepositController
{
    public function __construct(
        private readonly DepositRequestService $depositRequestService,
        private readonly DepositRequestRepository $depositRequestRepository,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
        }

        $dto = new CreateDepositRequestDto();
        $dto->externalReference = (string) ($data['external_reference'] ?? '');
        $dto->panel = (string) ($data['panel'] ?? 'binance');
        $dto->currency = strtoupper((string) ($data['currency'] ?? ''));
        $dto->network = isset($data['network']) ? strtoupper((string) $data['network']) : null;
        $dto->expectedAmount = (string) ($data['expected_amount'] ?? '');

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            return new JsonResponse(['error' => 'Validation failed.', 'details' => self::formatViolations($violations)], 422);
        }

        try {
            $result = $this->depositRequestService->createOrGetExisting(
                $dto->externalReference,
                $dto->panel,
                $dto->currency,
                $dto->network,
                $dto->expectedAmount,
            );
        } catch (PanelNotFoundException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 422);
        }

        $status = $result['created'] ? 201 : 200;
        if ($result['created'] && PaymentRequestStatus::SUBMIT_FAILED === $result['request']->getStatus()) {
            $status = 503;
        }

        return new JsonResponse(self::serialize($result['request']), $status);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid id.'], 400);
        }

        $depositRequest = $this->depositRequestRepository->find(Uuid::fromString($id));
        if (null === $depositRequest) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        return new JsonResponse(self::serialize($depositRequest));
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialize(DepositRequest $depositRequest): array
    {
        $data = [
            'id' => (string) $depositRequest->getId(),
            'external_reference' => $depositRequest->getExternalReference(),
            'status' => $depositRequest->getStatus()->value,
            'currency' => $depositRequest->getCurrency(),
            'network' => $depositRequest->getNetwork(),
            'expected_amount' => $depositRequest->getExpectedAmount(),
            'received_amount' => $depositRequest->getReceivedAmount(),
            'address' => $depositRequest->getAddress(),
            'address_tag' => $depositRequest->getAddressTag(),
            'expires_at' => $depositRequest->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ];

        if ($depositRequest->getPanel()->isTestPanel()) {
            $data['test_mode'] = true;
            $data['notice'] = BinanceTestPanel::NOTICE;
        }

        return $data;
    }

    /**
     * @return array<int, array{field: string, message: string}>
     */
    private static function formatViolations(iterable $violations): array
    {
        $result = [];
        foreach ($violations as $violation) {
            $result[] = [
                'field' => $violation->getPropertyPath(),
                'message' => $violation->getMessage(),
            ];
        }

        return $result;
    }
}
