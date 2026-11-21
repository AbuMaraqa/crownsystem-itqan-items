# Visual QA

Date: 2026-11-19. Local app at http://127.0.0.1:8010, Arabic (RTL), with `npm run dev`.

## Checked

| Page | Viewport | Result |
|---|---|---|
| Dashboard | 1536px | Sidebar 280px, header 70px with 30px padding, items 34px (14px/500, 18px icons, 8px radius), title 20px/500 at y=90. Same as the reference. |
| Users list | 1528px | Table toolbar 56px, 34px search and buttons, light table header, pagination 28px. No overflow. |
| Add user (form) | 1528px | Fields 34px. Found the sticky action bar 2px wider than the page (it used the old 32px bleed); fixed with `lg:-mx-gutter`. |
| Users list, dark mode | 1528px | Neutral surfaces and hairline borders, readable text. |
| Login | 1456px | Card 370px, fields 288×34, button 34px: the same as the reference. Brand panel mirrored for RTL. |
| Login | 390px (iframe) | No horizontal overflow, card 342px, brand panel becomes a strip above the form. Its long description is now hidden below `sm`. |
| Dashboard + drawer | 390px (iframe) | No overflow; the menu button opens the 280px drawer from the right in RTL. |
| 404 page | 1456px | Renders with its own CSS. |

## Fixed during QA

1. Password show/hide button sat on the left in Arabic while the field's padding was on the right (`dir="ltr"` flips logical padding).
   The field and button now share an LTR wrapper; text stays right-aligned in Arabic, hidden or shown.
2. `FormActions` bled 2px past the page after the gutter changed from 32px to 30px.
3. Sixteen module fields with their own 36px/40px heights were moved to `h-control`.

## Not checked

- 1920px, 1280px, 1024px and 768px. The browser window could not be resized below 1456px, and only 390px was checked through an iframe.
- Sign-in in English (LTR).
- A real sign-in submit, password reset or 2FA flow: the backend was not changed, and no credentials were typed.
- Every one of the 348 module pages. They use the shared components, so they get the change, but each was not opened.

## Differences from the reference that remain on purpose

- Font: the panel font is a setting (Tajawal by default, for Arabic), not Inter.
- Icons: Solar, not KeenIcons (licensed).
- Muted text is darker than the reference (#52525b instead of #71717a), as the owner asked.
- No social sign-in buttons and no "Sign up" link: the app has neither feature.
- The brand panel shows our own outline of the app, not Metronic's screenshot artwork.
