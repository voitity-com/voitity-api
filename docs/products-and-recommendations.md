# Products and Recommendation Guidance

Profiles can keep up to 15 products. The application-level catalog limit caps
the active plan capability, including the internal admin plan, so API responses,
CSV imports, manual creation, and the administrator all use the same value.

Only published products are supplied to the chat prompt. Product recommendations
must also be enabled for the profile and the Products feature must be effective.
The final response filters model-selected IDs against that published inventory
before product cards are attached.

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
2. A broad interest or need covered only by profile guidance produces a natural
   offer to help, without attaching cards.
3. If the visitor accepts the offer or explicitly requests recommendations, the
   assistant selects the smallest relevant set of published products.
4. Unrelated conversation must not introduce products.

Example guidance:

> If the visitor talks about football, soccer, or equipment needed for the
> sport, offer to help choose a ball. If the visitor talks about gaining muscle
> or complementing training, ask whether they want to see available supplements.

