# Asset sources

Illustrative photography is stored locally; page loads do not call external image services:

- hero-car.jpg: [Unsplash source](https://images.unsplash.com/photo-1492144534655-ae79c964c9d7), white sports car.
- lifestyle-car.jpg: [Unsplash source](https://images.unsplash.com/photo-1507136566006-cfc505b114fc), red coupe beside a stone building.
- detail-exterior.jpg: [Unsplash source](https://images.unsplash.com/photo-1503376780353-7e6692767b70), Porsche on a road.

Those original stock photos remain available as fallback images and on other pages. The homepage now also uses genuine SteamRex photographs and the video explicitly requested by the user. All are served from the project's assets folder; no third-party scripts, trackers or review widgets were copied.

## SteamRex homepage assets — 1 November 2026

| Local file | Original source |
| --- | --- |
| steamrex-exterior.jpg | https://steamrex.com/img/service/outside.jpg |
| steamrex-detail.jpg | https://steamrex.com/img/service/panel.jpg |
| steamrex-interior.jpg | https://steamrex.com/img/leather.4c08438c.jpg |
| steamrex-steam.jpg | https://steamrex.com/img/car_steam_back_varm.928efe75.jpg |
| steamrex-fleet.jpg | https://steamrex.com/img/hero/hero_fleet.jpg |
| steamrex-seat.jpg | https://steamrex.com/img/slides/seat.jpg |
| assets/video/steamrex-spring.mp4 | https://storage.googleapis.com/steamrexvideo/SR_Web_Generelt_Spring_Desktop3.mp4 |

The video is approximately 6.24 MB, muted, looped and played inline. It is fetched from this website only when playback is allowed. Reduced-motion/data-saver visitors get a still image with an explicit play button; scrolling out of view or hiding the tab pauses playback. A user pause is preserved. Failure leaves the image and booking links available.

The animated side-profile car, steam wordmark and line icons are original SVG/CSS artwork. The car is an illustration, not a photograph of a customer vehicle or an official SteamRex logo.

## Review attribution

The existing SteamRex homepage component explicitly marks Catharina, Marianne and Niclas as Google reviewers, with individual ratings 5, 5 and 4. The new site shows short translated excerpts with their names, individual ratings and a link back to that source. It does not claim a live Google feed or publish a fabricated average or aggregate-rating schema. The source was checked in the public homepage JavaScript `app.2ed5ebe0287ddd1d.js` on 1 November 2026. Admin can add/edit/archive attributed reviews under **Customer reviews**. Use an original/source HTTPS URL for each published excerpt.

Subscriptions and gift cards link to SteamRex's existing `/s365` and `/pages/gavekort` pages; no new subscription/payment checkout was added. Numerical package pricing and exact postcode/appointment availability remain managed by this portal's admin.
