<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Api\CreateWithdrawalRequestDto;
use App\Entity\WithdrawalRequest;
use App\Enum\PaymentRequestStatus;
use App\Repository\WithdrawalRequestRepository;
use App\Service\Exception\PanelNotFoundException;
use App\Service\WithdrawalRequestService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/withdrawals')]
final class WithdrawalController
{
    public function __construct(
        private readonly WithdrawalRequestService $withdrawalRequestService,
        private readonly WithdrawalRequestRepository $withdrawalRequestRepository,
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

        $dto = new CreateWithdrawalRequestDto();
        $dto->externalReference = (string) ($data['external_reference'] ?? '');
        $dto->panel = (string) ($data['panel'] ?? 'binance');
        $dto->currency = strtoupper((string) ($data['currency'] ?? ''));
        $dto->network = isset($data['network']) ? strtoupper((string) $data['network']) : null;
        $dto->amount = (string) ($data['amount'] ?? '');
        $dto->destinationAddress = (string) ($data['destination_address'] ?? '');
        $dto->destinationTag = isset($data['destination_tag']) ? (string) $data['destination_tag'] : null;

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            return new JsonResponse(['error' => 'Validation failed.', 'details' => self::formatViolations($violations)], 422);
        }

        try {
            $result = $this->withdrawalRequestService->createOrGetExisting(
                $dto->externalReference,
                $dto->panel,
                $dto->currency,
                $dto->network,
                $dto->amount,
                $dto->destinationAddress,
                $dto->destinationTag,
            );
        } catch (PanelNotFoundException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 422);
        }

        $status = $result['created'] ? 201 : 200;
        if ($result['created'] && PaymentRequestStatus::SUBMIT_FAILED === $result['request']->getStatus()) {
            $status = 502;
        }

        return new JsonResponse(self::serialize($result['request']), $status);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid id.'], 400);
        }

        $withdrawalRequest = $this->withdrawalRequestRepository->find(Uuid::fromString($id));
        if (null === $withdrawalRequest) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        return new JsonResponse(self::serialize($withdrawalRequest));
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialize(WithdrawalRequest $withdrawalRequest): array
    {
        return [
            'id' => (string) $withdrawalRequest->getId(),
            'external_reference' => $withdrawalRequest->getExternalReference(),
            'status' => $withdrawalRequest->getStatus()->value,
            'currency' => $withdrawalRequest->getCurrency(),
            'network' => $withdrawalRequest->getNetwork(),
            'amount' => $withdrawalRequest->getAmount(),
            'destination_address' => $withdrawalRequest->getDestinationAddress(),
            'tx_hash' => $withdrawalRequest->getTxHash(),
        ];
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
