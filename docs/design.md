# Design Rationale {#design-rationale}

`switon/http-cache` standardizes page caching at the HTTP boundary instead of scattering cache logic through
controllers.

## See also {#see-also}

- [HTTP Cache Package](README.md) — usage, configuration, and first integration
- [FAQ](faq.md#http-cache-faq) — common cache misses and setup questions

---

## Core Problem {#core-problem}

Page cache logic usually drifts into controller code, request state, and ad hoc `ETag` handling.
That makes the cache key easy to get wrong and the behavior hard to audit across requests.

## Primary Goal {#primary-goal}

Keep page caching declarative on the action and keep the lifecycle work in the filter.

- **Benefit:** the cache boundary stays visible at the handler.
- **Benefit:** revalidation and storage behave the same across actions.
- **Cost:** hidden state still has to be made explicit in the key.

## Core Choice {#core-choice}

The package separates cache lookup from cache storage across the HTTP lifecycle.

- `Switon\Http\Event\RequestReady` handles cache lookup and revalidation
- `Switon\Http\Event\HeadersSending` handles cache storage and response headers

That split keeps the action simple and keeps cache state request-scoped.

## Trade-Off {#trade-off}

The package favors request-derived keys and Redis-shared page bodies over more flexible per-destination routing.

- **Benefit:** repeated page responses are easy to reuse across processes.
- **Benefit:** `304 Not Modified` stays aligned with HTTP semantics.
- **Cost:** user-specific or session-specific variation must be designed into the key or kept out of page cache.
