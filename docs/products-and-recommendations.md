# Products and Recommendation Guidance

Profiles can keep up to 15 products. The application-level catalog limit caps
the active plan capability, including the internal admin plan, so API responses,
CSV imports, manual creation, and the administrator all use the same value.

Only published products are supplied to the chat prompt. Product recommendations
must also be enabled for the profile and the Products feature must be effective.
The final response filters model-selected IDs against that published inventory
before product cards are attached. Retrieval order is preserved so the most
relevant validated product is also the deterministic fallback for a concrete
need when the model omits the structured product action.

## Profile Guidance

`profiles.product_recommendation_guidance` stores optional plain-text routing
guidance for one profile. It is managed through:

```http
PATCH /api/profile/{profile}/products/settings
```

The request always includes `enabled` and may include
`recommendation_guidance`. Guidance is trimmed, empty text is stored as `null`,
and the default maximum length is 1,500 characters through
`PRODUCTS_RECOMMENDATION_GUIDANCE_MAX_LENGTH`.

Guidance helps the model recognize an indirect situation where offering product
help is appropriate. It is not factual product data and cannot override product
names, descriptions, publication state, health-claim restrictions, or other
system rules.

The expected conversation flow is:

1. A direct product mention or explicit request keeps the existing immediate
   recommendation and product-card behavior.
2. A concrete goal or need, such as wanting to build, create, improve, learn or
   obtain something, activates product recommendation retrieval even when the
   visitor does not say "product", "buy" or "recommend".
3. Retrieval must find a published product with sufficient semantic or lexical
   relevance. It reserves one slot for that product and one for recommendation
   guidance before filling the remaining context budget.
4. The assistant selects the smallest relevant set and describes it naturally.
   If it omits the structured action, the backend attaches the highest-ranked
   retrieved product. It never attaches a product that was not retrieved and
   revalidated as currently published.
5. A merely topical mention, weak semantic relationship, greeting, or unrelated
   conversation must not introduce products.

Intent is analyzed only from the current visitor message. Recent user and
assistant messages remain in the semantic query and generation prompt for
context, but provider words from an earlier assistant answer, such as YouTube,
cannot activate media intent or displace a product requested by the current
message.

Example guidance:

> If the visitor talks about football, soccer, or equipment needed for the
> sport, offer to help choose a ball. If the visitor talks about gaining muscle
> or complementing training, ask whether they want to see available supplements.
