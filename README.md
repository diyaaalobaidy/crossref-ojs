# OJS Crossref Manager & Export Plugin

This plugin for **Open Journal Systems (OJS) 3** handles Digital Object Identifier (DOI) registration, metadata export, Crossmark status badges, Cited-by metrics, and reference citation DOI matching with [Crossref](https://www.crossref.org/).

---

## Features

- **DOI Deposit & Metadata Export**: Register DOIs and deposit article/issue metadata directly with Crossref via their REST and deposit APIs, or export Crossref XML files for manual upload.
- **Detailed Error Logging & Frontend Inspection**: Every Crossref rejection, schema validation failure, and network error is recorded to the PHP error log and stored directly on the DOI entity for full inspection in the frontend DOI management interface (**View Error** modal).
- **Crossref Cited-by**: Query Crossref's Cited-by service to display citation counts and citing literature directly on article detail pages.
- **Citation DOI Resolution / Reference Linking**: Automatically query Crossref to match references in article bibliographies to their corresponding DOIs.
- **Crossmark Support**: Enable Crossmark participation and update policies for article versions, corrections, and retractions.

---

## Installation

The plugin must be installed into your OJS installation under `plugins/generic/crossref`.

### Option 1: Git Clone (Recommended)

Navigate to the `plugins/generic` folder of your OJS root and clone the repository into a directory named `crossref`:

```bash
cd /path/to/ojs/plugins/generic

# If an older crossref directory exists, back it up or remove it:
# rm -rf crossref

git clone https://github.com/diyaaalobaidy/crossref-ojs.git crossref
```

### Option 2: Git Submodule

If managing OJS via git:

```bash
cd /path/to/ojs
git submodule add https://github.com/diyaaalobaidy/crossref-ojs.git plugins/generic/crossref
git submodule update --init --recursive
```

---

## Configuration & Usage

1. **Enable the Plugin**:
   - In OJS, log in as an administrator or journal manager.
   - Go to **Settings > Website > Plugins > Generic Plugins**.
   - Ensure the **Crossref Manager Plugin** is enabled.

2. **Configure Crossref Credentials**:
   - Go to **Settings > Distribution > DOIs**.
   - Under the **Registration Agency** tab, select **Crossref**.
   - Enter your **Depositor Name**, **Depositor Email**, **Username**, and **Password**.
   - Optional: Enable **Test Mode** if verifying against Crossref's sandbox (`test.crossref.org`).

3. **Depositing DOIs & Inspecting Errors**:
   - Navigate to **DOIs** in the left sidebar menu.
   - Select published articles or issues and click **Deposit**.
   - If an error occurs, the item will be marked with an **Error** status and a **View Error** button will appear.
   - Click **View Error** to inspect the complete diagnostic report returned by Crossref, including individual record failures and raw XML responses.
   - All errors are also logged to the web server's PHP error log.

---

## Compatibility Note

This version resolves the PHP declaration compatibility issue:
```
Declaration of APP\plugins\generic\crossref\CrossrefExportPlugin::getStatusMessage(APP\core\Request $request): ?string must be compatible with APP\plugins\PubObjectsExportPlugin::getStatusMessage($request)
```
by ensuring `getStatusMessage($request)` signature matches the base `PubObjectsExportPlugin` class in OJS.

---

## License

This plugin is licensed under the [MIT License](LICENSE).
