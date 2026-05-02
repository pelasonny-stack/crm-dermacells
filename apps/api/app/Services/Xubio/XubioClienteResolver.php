<?php

declare(strict_types=1);

namespace App\Services\Xubio;

use App\Models\CustomerBillingEntity;

/**
 * Resolves a CustomerBillingEntity to its Xubio cliente id.
 *
 * Strategy:
 *   1. If the row already has xubio_cliente_id, use it.
 *   2. Otherwise lookup by CUIT in Xubio.
 *   3. If still not found, create the cliente in Xubio.
 *   4. Persist the resolved id to customer_billing_entities.xubio_cliente_id.
 *
 * Lookup-or-create avoids duplicates when CUIT already exists in Xubio
 * (e.g., manually created by a Director in the Xubio UI).
 */
class XubioClienteResolver
{
    public function __construct(
        private readonly XubioClient $xubio,
    ) {
    }

    public function resolve(CustomerBillingEntity $entity): string
    {
        if (! empty($entity->xubio_cliente_id)) {
            return $entity->xubio_cliente_id;
        }

        $hit = $this->xubio->findClienteByCuit($entity->cuit);
        $clienteId = $hit['id'] ?? null;

        if (! is_string($clienteId) || $clienteId === '') {
            $created = $this->xubio->createCliente($entity);
            $clienteId = $created['id'] ?? null;
        }

        if (! is_string($clienteId) || $clienteId === '') {
            throw new Exceptions\XubioRejectedException(
                'Could not resolve nor create Xubio cliente for CUIT ' . $entity->cuit
            );
        }

        $entity->forceFill(['xubio_cliente_id' => $clienteId])->save();

        return $clienteId;
    }
}
