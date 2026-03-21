# Equipment Management Design System: Editorial Precision

## 1. Overview & Creative North Star
### Creative North Star: "The Industrial Precisionist"
This design system moves away from the generic SaaS dashboard and toward a high-end, editorial experience tailored for industrial equipment management. It is defined by **Tonal Depth**, where hierarchy is communicated through light and texture rather than lines, and **Data Authority**, where information is presented with the clarity of a premium technical manual.

We break the "template" look by utilizing intentional asymmetry—placing high-impact metrics in oversized "Hero Cards"—and overlapping elements that suggest a sophisticated, multi-layered workspace. This system prioritizes functional elegance, replacing bulky 3D renders with sleek, equipment-focused data visualizations that feel engineered rather than decorated.

---

## 2. Colors
Our palette is a sophisticated interplay of deep charcoals and blacks, punctuated by high-vibrancy "Performance Accents."

*   **Primary (`#f3ffca` / `#cafd00`):** Use for active equipment states, healthy metrics, and primary calls to action. It conveys "Go/Ready."
*   **Secondary (`#feb700`):** Reserved for warnings, scheduled maintenance, and "Caution" states.
*   **Tertiary (`#81ecff`):** Used for technical data points, cooling systems, or digital telemetry.
*   **Error (`#ff7351`):** Strictly for critical failures or emergency stops.

### The "No-Line" Rule
To maintain a high-end aesthetic, **do not use 1px solid borders for sectioning.** Structural boundaries must be defined solely by background shifts. Use `surface-container-low` for the main background and `surface-container` for nested modules. The transition between these deep tones provides a cleaner, more modern separation than any stroke could achieve.

### The "Glass & Gradient" Rule
For "floating" overlays or top-level navigation, use Glassmorphism. Utilize semi-transparent surface colors with a `backdrop-filter: blur(20px)`. Main CTAs should not be flat; apply a subtle linear gradient from `primary` to `primary_container` to give buttons a "tactile glow" that feels premium and intentional.

---

## 3. Typography
The typography strategy balances industrial utility with high-end editorial style.

*   **Display & Headlines (Manrope):** Use Manrope for high-level summaries and equipment IDs. Its geometric nature feels engineered and modern. Bold weights in `display-lg` should be used for critical equipment uptime percentages.
*   **Body & Titles (Inter):** Inter is our workhorse for data tables and logs. It provides maximum legibility at small sizes (`body-sm`) for dense equipment specs.
*   **Technical Labels (Space Grotesk):** Space Grotesk is used for `label-md` and `label-sm`. Its monospaced feel is perfect for serial numbers, timestamps, and sensor readings, providing an "instrument cluster" aesthetic.

---

## 4. Elevation & Depth
Depth in this system is achieved through **Tonal Layering**, mimicking the way physical light interacts with high-end materials.

*   **The Layering Principle:** Stacking follows a logic of light. The "deepest" layer is `surface_container_lowest`. As an element becomes more interactive or important, it moves up to `surface_container_high`.
*   **Ambient Shadows:** Use shadows sparingly. When a card needs to "float," use a large 40px–60px blur with only 6% opacity, tinted with the `on_surface` color. This creates a soft ambient occlusion rather than a "drop shadow."
*   **The "Ghost Border" Fallback:** If accessibility requires a border, use `outline_variant` at 15% opacity. This creates a "glint" on the edge of the container without closing off the layout.

---

## 5. Components

### Cards & Metrics
*   **Rule:** No dividers. Use `spacing-8` or `spacing-10` to create grouping.
*   **Metric Cards:** Use a `surface_container_high` background. Align the value (Manrope) to the left and the trend indicator (Space Grotesk) to the top right. 
*   **Visual Soul:** Incorporate a subtle background gradient glow (5% opacity) of the status color (Primary for "Active," Secondary for "Service") in the corner of the card.

### Buttons
*   **Primary:** High-contrast `primary` background with `on_primary_fixed` text. Roundedness `full`.
*   **Secondary:** `surface_variant` background with a `ghost border`. 
*   **States:** On hover, primary buttons should increase their "glow" (shadow spread) rather than just changing color.

### Equipment Status Chips
*   **Selection Chips:** Use `secondary_container` for active states with a high-vibrancy `secondary` dot.
*   **Filter Chips:** Small, pill-shaped, using `surface_container_highest` to stand out against the main dashboard background.

### Input Fields
*   **Style:** Minimalist. No background fill—only a bottom "Ghost Border" using `outline_variant`. On focus, the border transitions to a 2px `primary` line.

### Equipment Lists
*   **Interaction:** Rows should not have dividers. Use a `surface_bright` background change on hover with a `DEFAULT` (0.25rem) corner radius to highlight the active selection.

---

## 6. Do's and Don'ts

### Do:
*   **Do** use `primary_container` for data visualizations (bar charts, gauges) to ensure they "pop" against the dark background.
*   **Do** allow for generous negative space. High-end design feels expensive because it isn't "crowded."
*   **Do** use asymmetrical layouts (e.g., a 70/30 split) to guide the eye toward the most critical equipment status.

### Don't:
*   **Don't** use pure white (`#ffffff`) for body text; use `on_surface_variant` for secondary data to reduce eye strain and improve hierarchy.
*   **Don't** use 3D bevels or heavy drop shadows. The "depth" must come from the color palette.
*   **Don't** use standard "Success Green." Always use our signature Neon-Green/Yellow (`primary`) to maintain the custom identity.
*   **Don't** use divider lines to separate list items; let the `spacing` scale do the work.