from __future__ import annotations

import json
import shutil
from collections import defaultdict
from pathlib import Path
from urllib.parse import urljoin

from jinja2 import Environment, FileSystemLoader, select_autoescape

ROOT = Path(__file__).resolve().parent
DATA_DIR = ROOT / "data"
TEMPLATES_DIR = ROOT / "templates"
SITE_DIR = ROOT / "site"
ASSETS_DIR = TEMPLATES_DIR / "assets"


def load_json(path: Path):
    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle)


def slugify(value: str) -> str:
    return "-".join("".join(char.lower() if char.isalnum() else " " for char in value).split())


def ensure_dir(path: Path) -> None:
    path.mkdir(parents=True, exist_ok=True)


def build_url(base_url: str, relative_path: str) -> str:
    return urljoin(base_url.rstrip("/") + "/", relative_path)


def write_text(path: Path, content: str) -> None:
    ensure_dir(path.parent)
    path.write_text(content, encoding="utf-8")


def root_prefix(relative_path: str) -> str:
    depth = len(Path(relative_path).parts) - 1
    if depth <= 0:
        return ""
    return "../" * depth


def sitewide_schemas(site: dict, base_url: str) -> list[str]:
    organization = {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": site["brand_name"],
        "url": base_url,
        "logo": build_url(base_url, site["brand"]["logo"].lstrip("/")),
        "sameAs": list(site["social_links"].values()),
        "contactPoint": [{
            "@type": "ContactPoint",
            "contactType": "customer support",
            "email": site["contact_email"]
        }]
    }
    website = {
        "@context": "https://schema.org",
        "@type": "WebSite",
        "name": site["brand_name"],
        "url": base_url,
        "description": site["default_meta_description"],
        "potentialAction": {
            "@type": "SearchAction",
            "target": build_url(base_url, "collections/index.html")
        }
    }
    return [json.dumps(organization, ensure_ascii=False), json.dumps(website, ensure_ascii=False)]


def product_schema(site: dict, base_url: str, product: dict, product_url: str) -> str:
    availability_map = {
        "in_stock": "https://schema.org/InStock",
        "limited_stock": "https://schema.org/LimitedAvailability",
        "made_to_order": "https://schema.org/PreOrder",
        "out_of_stock": "https://schema.org/OutOfStock",
    }
    schema = {
        "@context": "https://schema.org",
        "@type": "Product",
        "name": product["title"],
        "description": product["description"],
        "sku": product["id"],
        "image": product["images"],
        "brand": {"@type": "Brand", "name": product.get("brand", site["brand_name"] )},
        "offers": {
            "@type": "Offer",
            "priceCurrency": "USD",
            "price": product["price_usd"],
            "availability": availability_map.get(product["stock_status"], "https://schema.org/InStock"),
            "url": product_url
        }
    }
    return json.dumps(schema, ensure_ascii=False)


def faq_schema(items: list[dict]) -> str:
    schema = {
        "@context": "https://schema.org",
        "@type": "FAQPage",
        "mainEntity": [
            {
                "@type": "Question",
                "name": item["question"],
                "acceptedAnswer": {"@type": "Answer", "text": item["answer"]}
            }
            for item in items
        ]
    }
    return json.dumps(schema, ensure_ascii=False)


def render_page(env: Environment, template_name: str, output_path: Path, *, relative_path: str, site: dict, base_url: str, meta: dict, extra_context: dict | None = None, schemas: list[str] | None = None) -> None:
    template = env.get_template(template_name)
    context = {
        "site": site,
        "meta": meta,
        "root_prefix": root_prefix(relative_path),
        "canonical_url": build_url(base_url, relative_path),
        "schemas": sitewide_schemas(site, base_url) + (schemas or [])
    }
    if extra_context:
        context.update(extra_context)
    write_text(output_path, template.render(**context))


def copy_assets() -> None:
    ensure_dir(SITE_DIR / "assets")
    shutil.copy2(ASSETS_DIR / "styles.css", SITE_DIR / "assets" / "styles.css")
    shutil.copy2(ASSETS_DIR / "app.js", SITE_DIR / "assets" / "app.js")
    logo = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 64" role="img" aria-label="PrivacyArmy logo"><rect width="240" height="64" rx="16" fill="#101524"/><text x="24" y="41" fill="#fff" font-family="Inter, Arial, sans-serif" font-size="28" font-weight="700">PrivacyArmy</text></svg>'
    write_text(SITE_DIR / "assets" / "privacyarmy-logo.svg", logo)


def generate() -> None:
    site = load_json(DATA_DIR / "site_config.json")
    products = load_json(DATA_DIR / "products.json")
    faq_items = load_json(ROOT / "support" / "faq.json")
    base_url = f"https://{site['domain']}"

    blog_posts = [
        {
            "slug": "privacy-phone-buyers-guide",
            "title": "How to choose a privacy-focused smartphone in 2026",
            "excerpt": "A starter framework for comparing GrapheneOS phones, rooted Android builds, and jailbroken iPhones based on your threat model.",
            "published_at": "2026-01-15",
            "body": [
                "A privacy-focused phone purchase starts with your threat model. Some buyers want strong sandboxing and long-term updates, while others need root access for research or app analysis.",
                "GrapheneOS-ready Pixels remain the easiest recommendation for many buyers because the install path and ongoing maintenance story are comparatively mature.",
                "Rooted Android and jailbroken iPhone builds are narrower, specialist purchases. They make sense when you explicitly need them, but they also require more upkeep and sharper operational discipline."
            ]
        }
    ]

    collections = []
    grouped = defaultdict(list)
    for product in products:
        grouped[product["category"]].append(product)
    for category, items in grouped.items():
        collections.append({"name": category, "slug": slugify(category), "products": items})
    collections.sort(key=lambda item: item["name"])

    if SITE_DIR.exists():
        shutil.rmtree(SITE_DIR)
    ensure_dir(SITE_DIR)
    copy_assets()

    env = Environment(
        loader=FileSystemLoader(str(TEMPLATES_DIR)),
        autoescape=select_autoescape(["html", "xml"]),
        trim_blocks=True,
        lstrip_blocks=True,
    )

    sitemap_paths: list[str] = []

    def add_page(path_str: str, template_name: str, meta: dict, extra_context: dict | None = None, schemas: list[str] | None = None) -> None:
        output_path = SITE_DIR / path_str
        render_page(
            env,
            template_name,
            output_path,
            relative_path=path_str,
            site=site,
            base_url=base_url,
            meta=meta,
            extra_context=extra_context,
            schemas=schemas,
        )
        sitemap_paths.append(path_str)

    add_page(
        "index.html",
        "home.html",
        {
            "title": f"{site['brand_name']} | Privacy-focused smartphones & crypto checkout",
            "description": site["default_meta_description"],
            "image": build_url(base_url, "assets/privacyarmy-logo.svg"),
        },
        {
            "featured_products": products[:4],
            "collections": collections,
            "blog_post": blog_posts[0],
        },
    )

    add_page(
        "collections/index.html",
        "collection.html",
        {
            "title": f"Collections | {site['brand_name']}",
            "description": "Explore PrivacyArmy product categories for GrapheneOS devices, rooted Android, jailbroken iPhone builds, and privacy services.",
            "image": products[0]["images"][0],
        },
        {"collection": {"name": "All collections", "products": products}},
    )

    for collection in collections:
        add_page(
            f"collections/{collection['slug']}/index.html",
            "collection.html",
            {
                "title": f"{collection['name']} | {site['brand_name']}",
                "description": f"Shop {collection['name']} at {site['brand_name']} with crypto checkout via NOWPayments.",
                "image": collection["products"][0]["images"][0],
            },
            {"collection": collection},
        )

    for product in products:
        collection = next(item for item in collections if item["name"] == product["category"])
        related = [item for item in collection["products"] if item["id"] != product["id"]][:3]
        relative_path = f"products/{product['slug']}/index.html"
        add_page(
            relative_path,
            "product.html",
            {
                "title": f"{product['title']} | {site['brand_name']}",
                "description": product["short_description"],
                "og_type": "product",
                "image": product["images"][0],
            },
            {"product": product, "collection": collection, "related_products": related},
            [product_schema(site, base_url, product, build_url(base_url, relative_path))],
        )

    add_page(
        "blog/index.html",
        "blog_index.html",
        {
            "title": f"Blog | {site['brand_name']}",
            "description": "PrivacyArmy articles covering privacy-phone buying guides, setup strategy, and operational security basics.",
            "image": products[0]["images"][0],
        },
        {"posts": blog_posts},
    )

    for post in blog_posts:
        add_page(
            f"blog/{post['slug']}/index.html",
            "blog_post.html",
            {
                "title": f"{post['title']} | {site['brand_name']}",
                "description": post["excerpt"],
                "image": products[0]["images"][0],
            },
            {"post": post},
        )

    add_page(
        "support/faq/index.html",
        "faq.html",
        {
            "title": f"Support FAQ | {site['brand_name']}",
            "description": "Answers about PrivacyArmy checkout, crypto payments, fulfillment, and custom device support.",
            "image": build_url(base_url, "assets/privacyarmy-logo.svg"),
        },
        {"faq_items_json": json.dumps(faq_items)},
        [faq_schema(faq_items)],
    )

    sitemap_entries = "\n".join(f"  <url><loc>{build_url(base_url, path)}</loc></url>" for path in sitemap_paths)
    write_text(
        SITE_DIR / "sitemap.xml",
        f'<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n{sitemap_entries}\n</urlset>\n',
    )
    write_text(
        SITE_DIR / "robots.txt",
        f"User-agent: *\nAllow: /\n\nSitemap: {build_url(base_url, 'sitemap.xml')}\n",
    )


if __name__ == "__main__":
    generate()
