# Freeplast — design system

## Direction

A mobile-first **Trust & Authority + Conversion** landing page with an organic industrial visual language. The experience should feel durable, circular and commercially direct—not like a generic eco startup.

The ui-ux-pro-max recommendation was used for structure, organic styling, conversion strategy and motion. Its generic green palette was intentionally rejected because this proposal must preserve Freeplast's existing colors.

## Brand tokens

```css
--blue: #100090;
--blue-dark: #0d075d;
--blue-deep: #09043f;
--blue-soft: #e9e7fb;
--green: #306020;
--green-bright: #4a813e;
--green-soft: #edf4ea;
--ink: #11121a;
--muted: #656775;
--line: #dfe1e8;
--paper: #f7f8f5;
--white: #ffffff;
```

No additional hues. Error red is reserved for validation feedback only.

## Typography

- Family: **Plus Jakarta Sans**
- Display: 700–800, tight tracking, balanced wrapping
- Body: 400–500, 1.5–1.7 line height
- Labels: 700–800, compact uppercase tracking

## Shape and depth

- Pills for primary actions and compact status labels
- 14–44 px radii for cards and large visual frames
- Soft, tinted shadows rather than black drop shadows
- Circular/orbit motifs echo the arrows in the Freeplast mark
- Grid textures communicate engineering precision without introducing new colors

## Page structure

1. Hero: proposition, product in context, two direct CTAs
2. Selected catalog: swipeable product rail with product-to-form shortcuts
3. Purpose: circular-material story supported by a real product image
4. Company: local origin and industrial credibility
5. Contact: phone, email, location and low-friction quote form
6. Footer

## Interaction

- Motion tier: standard (5/10)
- Reveal content once; avoid continuous decorative animation
- Product rail supports touch drag, mouse drag, arrow keys and explicit buttons
- Press states use stable scale/opacity changes and never shift surrounding layout
- Respect `prefers-reduced-motion` and `prefers-reduced-transparency`

## Form behavior

- Keep inline labels and specific inline errors
- On failed submit, show and focus a linked error summary
- Product CTAs preselect the matching product before moving to the form
- Do not fake a successful network request in the prototype

## Avoid

- Generic green-first eco branding or unsupported sustainability claims
- Decorative ratings, invented customer logos or unverified statistics
- Emoji/symbol characters as structural icons
- Auto-rotating carousels
- Low-contrast muted text or translucent controls without a solid fallback
