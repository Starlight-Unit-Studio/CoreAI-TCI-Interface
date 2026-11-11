# OpenPet community sprites: redistribution audit

Date: 2026-11-11

The supplied OPENPETS.zip contains **56 individual .openpet.zip archives**, each with `pet.json` and `spritesheet.webp`. All use the supported OpenPet atlas geometry: **47** v2 atlases (1536x2288) and **9** v1 atlases (1536x1872). One sprite is RGB rather than RGBA. None of the nested archives supplies an individual artwork redistribution license, signed copyright grant, or evidence that the community contributor owns the depicted character.

**Decision: 0 imported as public default assets.** The original nine CoreAI pets including Mara stay unchanged. The new admin-curated library import explicitly requires a rights holder, redistribution-compatible license, source URL, specific proof URL, and owner confirmation. All user-contributed content remains out of the official distributable until verified.

## Possible original motifs, but license/character rights still unverified
- astra
- crosspost-buddy
- friday-v1
- peachy
- phra-whey
- sexy-dancer-v2
- shuangling

No inference from an original-sounding name establishes ownership or redistribution permission. Review the full sprites, source, and creator rights before approval.

## Recognizable third-party IP and other risks

Examples in the archive include: `a-link-from-the-past`, `aang-airbender`, `agnes-tachyon`, `bulma-braid-feng`, `chibi-naruto`, `chibi-skeletor`, `cloud-strife-chibi`, `crayon-shin-chan`, `goku-feng`, `homer-simpson`, `jedi-master`, `jiraiya`, `kakashi-hatake`, `kenny`, `link-botw`, `michelin-man`, `naruto-uzumaki`, `paimon-v2`, `piccolo-z-feng`, `tali-zorah`, `the-mask`, `vegeta-z-feng`, `yui-sao`, and `zoro`. The remaining packages include other likely franchise characters, likenesses, memes and/or insufficiently sourced contributors. `bowcat` visually evokes a recognizable cat character and is not presumed free of restrictions. Public-domain status of an underlying concept does not automatically license the uploaded sprite artwork.

A gallery hosting these ZIPs or open-sourcing its application code is not itself a permission to copy and redistribute individual user-contributed sprites, particularly recognizable third-party characters.

## Technical implementation
- Never publish the 96 MB source bundle or unverified sprites in GitHub releases or images/pets.
- Existing sprite animation and nine built-ins remain compatible.
- Approved sprites are stored under the protected `var/console_media/pet_library` directory, not public static asset paths.
- Owner-only authenticated ZIP import checks format, size, ID, duplicate entries, license metadata, and safe atomic manifest updates.
- All accounts may select only explicitly approved shared library entries; the sprite endpoint remains authenticated.
- An unpublished selection falls back to Mara without altering account-owned pets.
- The source ZIP itself stays outside the repository until individually licensed sprites can be approved.

This audit is a technical and provenance screening, not a legal opinion or a certification of rights.
