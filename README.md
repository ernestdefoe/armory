# Armory

A **World of Warcraft armory** for [Flarum 2](https://flarum.org). Members sign in
with **Battle.net**, and the extension pulls their characters straight from the
Blizzard API — gear with hover tooltips, stats, talents, Mythic+/raid progress,
professions, PvP ratings, reputations and collections — on a tabbed, theme-aware
character page that looks at home in light or dark mode.

> Free and open source (MIT). The companion [Convoro](https://convoro.co) build
> shares the same data engine.

## Features

- **Battle.net OAuth** — one click to connect; characters sync automatically.
- **Full character page** at `/armory` with tabs:
  - **Gear** — every slot with a big 3D character render and Wowhead-style item tooltips (item level, stats, sockets, set bonuses, sell price).
  - **Stats** — primary + secondary stats, defenses and resources.
  - **Talents** — active spec, talents, and the in-game loadout import string.
  - **PvE** — Mythic+ rating and best weekly runs, plus raid progress.
  - **Professions** — primary and secondary, with tier skill.
  - **PvP** — honor level and rated 2v2 / 3v3 / RBG ratings.
  - **Reputations** and **Achievements / Collections** (points, mounts, pets).
- **Roster sidebar** to switch between your characters; pick a **main**, hide alts.
- **Role-Play tie-in** — if [ernestdefoe/roleplay](https://github.com/ernestdefoe/roleplay) is installed, an **Add to Role-Play** button imports a WoW character as a playable Role-Play character: a combat sheet (HP + might/agility/wits/heart scaled from item level and primary stats) plus a deck of signature **class ability cards** whose damage dice scale with item level. Re-run after a gear upgrade to rescale.
- **Theme-aware** — colors follow your forum's light/dark scheme.
- **Caching** — character data is cached so the page stays fast and stays well
  under Blizzard's rate limits.

## Setup

1. Install:
   ```bash
   composer require ernestdefoe/armory
   php flarum migrate
   php flarum cache:clear
   ```
2. Create a Battle.net API client at
   [develop.battle.net/access/clients](https://develop.battle.net/access/clients).
   Set the **Redirect URL** to `https://YOUR-FORUM/auth/battlenet/callback`.
3. In **Admin → Armory**, paste the **Client ID** and **Client Secret**, and
   choose your default **region** (Americas / Europe / Korea / Taiwan).
4. Members open **Armory** from the navigation and click **Connect Battle.net**.

## How it works

Public character data (gear, stats, raids, …) is fetched with an app-level
client-credentials token, so refreshes never require the member to sign in
again. The member's `wow.profile` token is used only once — to discover which
characters belong to them.

## License

[MIT](LICENSE) © ernestdefoe
