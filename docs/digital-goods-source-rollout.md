# Digital Goods Source Rollout

Digital Goods Source rollout is an authority migration, not a blind deployment.

## Invariant

Provider authority is exclusive:

- Meanly calls the Digital Goods Source contract.
- Digital Goods Source calls provider APIs.
- Meanly does not store provider credentials or call provider APIs directly after cutover.

## Gates

1. Shadow deployment: run `digital-goods-source` beside the marketplace-local kernel.
2. Catalog parity: compare item count, aliases, service SKU, market SKU, prices, currency and active state.
3. Availability parity: compare availability responses and partner affordability for representative SKUs.
4. Order parity: submit test/idempotent orders only against sandbox or fake providers.
5. Partner credit/top-up parity: verify HMAC signing, replay window, idempotency and balance behavior.
6. Production cutover: set `DIGITAL_GOODS_SOURCE_URL` in Meanly and move `WILDFLOW_KERNEL_MODE` to `http`.

Financial flows must not cut over until signature, replay, idempotency and balance behavior match the marketplace-local behavior.
