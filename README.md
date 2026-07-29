# Supercraft Technical SEO Engine for Elementor & AIOSEO

**Supercraft Technical SEO** is a high-performance WordPress plugin designed for the **Supercraft Plugin Universe**. It provides a single-click post-completion workflow for WordPress websites built with **Elementor** and using **All in One SEO (AIOSEO)**.

---

## ⚡ Key Features

1. **Elementor Data Parsing Engine (`_elementor_data`)**
   - Automatically inspects Elementor's recursive JSON tree structure.
   - Extracts structured H1/H2/H3 headings, body copy, icon boxes, call-to-actions, and image elements while discarding layout wrapper noise.

2. **OpenAI Automated Meta Tag Generator**
   - Leverages your OpenAI API key (using models like `gpt-4o-mini` or `gpt-4o`).
   - Automatically generates:
     - **Meta Title** (under 60 chars, CTR-optimized)
     - **Meta Description** (140–155 chars with compelling Call-To-Action)
     - **Focus Keyphrase & LSI Secondary Keywords**
     - **Social OpenGraph (OG) Titles & Descriptions**
     - **Suggested Image ALT Attributes** for missing images.

3. **Seamless All in One SEO (AIOSEO) Integration**
   - Populates standard AIOSEO post meta (`_aioseo_title`, `_aioseo_description`, `_aioseo_og_title`, `_aioseo_focus_keyphrase`, etc.).
   - Directly updates AIOSEO's custom database table (`wp_aioseo_posts`) so meta tags immediately render on the frontend header.

4. **One-Click Post-Completion Technical SEO Audit**
   - Scans all published and draft pages/posts.
   - Checks H1 tag structure (verifies exactly 1 H1 per page).
   - Audits content depth and flags thin content (<300 words).
   - Detects images missing ALT attributes with a 1-click **"Fix ALTs via AI"** button.
   - Audits page indexability (`noindex` tag check) and draft status.

---

## 📂 File Architecture

```
supercraft-seo/
├── supercraft-seo.php              # Plugin entry point & bootstrap
├── README.md                        # Documentation & setup guide
├── assets/
│   ├── css/admin.css                # Premium Supercraft styled dashboard UI
│   └── js/admin.js                  # AJAX batch runner & interactive report
└── includes/
    ├── class-supercraft-seo.php     # Main plugin orchestrator
    ├── class-elementor-parser.php   # Extracts clean copy from _elementor_data
    ├── class-openai-service.php     # OpenAI API integration & structured JSON
    ├── class-aioseo-bridge.php      # Writes meta into AIOSEO post meta & DB table
    ├── class-seo-auditor.php        # Technical SEO diagnostic suite
    └── class-admin-dashboard.php    # WP Admin page & AJAX endpoints
```

---

## 🚀 Installation & Setup

1. Copy the `supercraft-seo` directory into your WordPress plugins folder: `wp-content/plugins/supercraft-seo/`.
2. Activate **Supercraft Technical SEO Engine** from the WP Admin **Plugins** screen.
3. Navigate to **Supercraft SEO** in the left admin menu.
4. Input your **OpenAI API Key** and select your preferred model (`gpt-4o-mini` recommended).
5. Click **"Run One-Click Technical SEO & Auto-Fix"** to automatically process and populate AIOSEO fields across all finished pages.

---

## 🔒 Security & Privacy

- All AJAX endpoints enforce nonce verification (`wp_create_nonce`) and capability checks (`manage_options`).
- Input fields are strictly sanitized (`sanitize_text_field`, `sanitize_textarea_field`, `esc_url_raw`).
- Database queries use `$wpdb->prepare()`.
- API keys are stored securely using WordPress Options API (`update_option`).
