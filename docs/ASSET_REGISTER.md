# Asset Register

**Hotel Booking System — ICT304 Capstone 2**

A record of every image and video file supplied with this project, where each
is used, and what is known about its origin.

> ## ⚠️ PROVENANCE IS UNVERIFIED
>
> **The original source and licence of every media file in this project are
> unknown to whoever prepared this register.** The files were already present
> in the project folder when the current work began; no accompanying licence,
> attribution or download record was supplied with them.
>
> Nothing in this document invents a source, photographer, licence or URL.
> Every row is marked **Source confirmation required from team**.
>
> **This must be resolved before submission.** Using photographs without a
> known licence is an academic-integrity and copyright risk. See
> [Action required](#action-required).

---

## 1. Room photography

| Folder | Files | Used on | Source known? |
|---|---|---|---|
| `images/StandardTwinRoom/` | `1.jpg`, `2.jpg`, `3.jpg`, `4.jpg` | `StandardTwinRoom.html` (gallery); `2.jpg` also on the home page room grid | ❌ **Source confirmation required from team** |
| `images/ExecutiveTwinRoom/` | `1.jpg`, `2.avif`, `3.jpg`, `4.jpg`, `5.jpg`, `6.jpg` | `ExecutiveTwinRoom.html` (gallery uses 5, 3, 4, 6); `6.jpg` also on the home page room grid | ❌ **Source confirmation required from team** |
| `images/SuperiorSuite/` | `1.avif`, `3.jpg`, `4.jpg`, `6.jpg`, `7.jpg`, `8.webp` | `SuperiorSuite.html` (gallery uses 1, 3, 7, 8); `8.webp` also on the home page room grid | ❌ **Source confirmation required from team** |
| `images/DeluxeSuite/` | `2.jpg`, `3.jpg`, `4.jpg`, `5.webp`, `6.jpg` | `DeluxeSuite.html` (gallery uses 2, 3, 5, 4); `5.webp` also on the home page room grid | ❌ **Source confirmation required from team** |
| `images/ExecutiveSuite/` | `1.webp`, `2.jpg`, `3.jpg`, `4.jpg`, `5.webp` | `ExecutiveSuite.html` (gallery uses 2, 3, 5, 4); `5.webp` also on the home page room grid | ❌ **Source confirmation required from team** |
| `images/PresidentialSuiteRoom/` | `1.webp`, `2.webp`, `3.webp`, `4.webp`, `5.webp` | `PresidentialSuite.html` (gallery uses 1, 2, 3, 4); `5.webp` also on the home page room grid | ❌ **Source confirmation required from team** |

The room photographs are also referenced from the database: each row in
`room_types.image_path` points at one of the files above.

## 2. Site imagery

| File | Used on | Source known? |
|---|---|---|
| `images/home_bg.jpg` | **Home page hero background** (`index.css`), and background for the login, registration, logout, booking and legacy pages (`css.css`, `booknow.css`) | ❌ **Source confirmation required from team** |
| `images/reserve.jpg` | Banner background on all six room pages (`room.css`) | ❌ **Source confirmation required from team** |

`images/home_bg.jpg` is now the most prominent image in the project — it is
the first thing a visitor sees. Its licence is still **unknown**, so
confirming its provenance is the highest priority in this register.

## 3. Video

**No video files remain in this project.**

`video/video.mp4` (~4.7 MB) was the home page hero background. It rendered
noticeably blurry and played back with visible lag, so it was removed and the
hero now uses the sharper, lighter `images/home_bg.jpg` instead. The file was
deleted in the commit "Replace laggy hero video with static image" and remains
recoverable from Git history if it is ever needed.

Its provenance was never established either. If the file is ever restored, the
same **source confirmation required from team** warning applies to it.

## 4. Currently unused assets

These files are present in the repository but are **not referenced by any
page or stylesheet**. They have been kept, not deleted.

| File | Size | Note | Source known? |
|---|---|---|---|
| `images/home_banner.jpg` | ~22 KB | **Became unused** when the hero video was removed — it had been the video's poster frame. Kept, not deleted | ❌ **Source confirmation required from team** |
| `images/logo.png` | ~278 KB | Not used. The current wordmark is text plus a CSS-drawn "HB" mark, so no logo image is needed | ❌ **Source confirmation required from team** |
| `images/facebook.png` | ~0.5 KB | Not used — no social media links exist on the site | ❌ **Source confirmation required from team** |
| `images/twitter.png` | ~0.6 KB | Not used — no social media links exist on the site | ❌ **Source confirmation required from team** |
| `images/us/asif.jpg` | ~117 KB | Not used. Appears to be a team member photograph | ❌ **Source confirmation required from team** — and see the note below |
| `images/us/jinat.jpg` | ~14 KB | Not used. Appears to be a team member photograph | ❌ **Source confirmation required from team** — and see the note below |
| `images/us/mamun.jpg` | ~944 KB | Not used. Appears to be a team member photograph | ❌ **Source confirmation required from team** — and see the note below |

**Note on `images/us/`.** These three files appear to be photographs of
identifiable people. If they are ever displayed on the site, the consent of
each person shown is required in addition to any licence question. They are
currently not referenced by any page.

## 5. Assets created for this project

These were authored as part of the current work and have no third-party
provenance question:

| Asset | Where |
|---|---|
| Colour palette, typography scale, spacing scale | `theme.css` |
| "HB" wordmark mark | CSS only (`.brand-mark` in `theme.css`) — no image file |
| All page copy and alternative text | The HTML and PHP pages |

---

## Action required

Before submission, the team must, for **every** file listed above:

1. Identify where it came from — a stock library, a specific photographer, a
   generator, or a team member's own camera.
2. Record the licence that permits its use in coursework.
3. Record the URL and the date it was obtained, where applicable.
4. Add the correct attribution to the submission's reference list, in the
   format required by the unit.

If a file's origin cannot be established, the safest course is to **replace
it** with an asset whose licence is documented, or remove it.

**No entry in this register may be filled in with a guess.** An invented
source is worse than an acknowledged gap.

| Field to complete | Who can answer |
|---|---|
| Original source / URL | Whoever added the files to the project |
| Licence terms | Same |
| Date obtained | Same |
| Consent for `images/us/` photographs | The people shown |
