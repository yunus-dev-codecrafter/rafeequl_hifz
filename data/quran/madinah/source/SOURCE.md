# SOURCE - canonical Quran structural dataset (Madinah Mushaf)

Acquired by `tools/quran-data/acquire.php`. Files in this directory are
**preserved originals** - never edit them; the pipeline verifies SHA-256
sidecars before every stage (data-architecture §7).

Date obtained (UTC): **2026-10-05T19:43:46+00:00**

## Primary source

| field | value |
| --- | --- |
| name | Tanzil Quran Metadata (quran-data.xml) |
| official URL | https://tanzil.net/res/text/metadata/quran-data.xml |
| dataset / version | quran-data.xml / 1.0 |
| license / terms | CC BY 3.0 + Tanzil terms of use (https://tanzil.net/docs/text_license) |
| copyright | (C) 2008-2009 Tanzil.info |
| file | `quran-data.xml` (77234 bytes) |
| SHA-256 | `8867c1d88191472adec9db694b3cd9f135b1a2ef580574d32cf888dcb22c5c7a` |
| contains | Sura list (numbers, ayah counts, 0-based global starts, Arabic/transliteration/English names, revelation type, ruku counts), juz starts (30), rub/quarter starts (240), Madinah page starts (604), manzil/ruku/sajda metadata |

Attribution (required by the license): Quran structural metadata by
**Tanzil.net**, (C) 2008-2009 Tanzil.info, licensed under
[CC BY 3.0](https://creativecommons.org/licenses/by/3.0/) with the
[Tanzil terms of use](https://tanzil.net/docs/text_license) - this
project links to <https://tanzil.net> wherever the dataset is presented.

## Independent cross-check sources (data-architecture §8.2)

Two independent providers are diffed before import; any disagreement
halts the pipeline (resolution = printed Madinah Mushaf):

| field | value |
| --- | --- |
| alquran.cloud full Quran (quran-uthmani edition) | https://api.alquran.cloud/v1/quran/quran-uthmani |
| terms | alquran.cloud terms and conditions (fair use with attribution) (https://alquran.cloud/terms-and-conditions) |
| file | `alquran-quran-uthmani.json` (2109200 bytes) |
| SHA-256 | `0df03e1d6da4fc8138208fec1688f2b416f0dd4ebbd514179f3e5e0fbaf4195f` |
| contains | Per-ayah structural fields: global number, numberInSurah, juz, manzil, page, ruku, hizbQuarter (ayah text is parsed for position only and never stored) |

| alquran.cloud meta | https://api.alquran.cloud/v1/meta |
| terms | alquran.cloud terms and conditions (fair use with attribution) (https://alquran.cloud/terms-and-conditions) |
| file | `alquran-meta.json` (53035 bytes) |
| SHA-256 | `9444749ea0b261054d4f744d4aec38286e666b25f1cf8aa78ce0a24f789caa5c` |
| contains | Surah list (114), juz start references (30), rub start references (240), page start references (604), ayah total (6236), ruku and sajda lists |

Why multiple sources: the primary file alone cannot independently prove
page straddles (it stores page *starts* only); the cross-check provider
stores per-ayah start pages and division memberships, so boundaries are
verified against genuinely separate data sets.

## Reproduce

```text
php tools/quran-data/acquire.php       # download + sidecars + this file
php tools/quran-data/normalize.php     # processed/*.json (deterministic)
php tools/quran-data/verify.php        # verification/report-*.json
php tools/quran-data/import.php --dry-run
php tools/quran-data/import.php --execute
php tools/quran-data/audit.php         # invariants against the database
```

No API keys or private credentials are used by any stage. Ayah *text*
is fetched by the cross-check file but never stored by this pipeline.
