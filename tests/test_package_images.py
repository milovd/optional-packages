"""Package-owned, optional image metadata contract (no Core runtime code here)."""

import json
import struct
import unittest
from pathlib import Path
from urllib.parse import urlsplit


ROOT = Path(__file__).resolve().parents[1]
IMAGE_PACKAGES = {
    "modules/digital-delivery": ("digital-delivery", "1.1.1"),
    "modules/domains": ("domains", "1.1.1"),
    "modules/downloads": ("downloads", "1.1.1"),
    "modules/events": ("events", "1.1.1"),
    "modules/provisioning": ("provisioning", "1.1.1"),
    "extensions/provisioning/pterodactyl": ("pterodactyl", "1.0.2"),
}


def image_path(package: Path, reference: object) -> Path | None:
    """Test the declared contract, not a runtime image URL resolver."""
    if not isinstance(reference, str) or not reference:
        return None
    if (urlsplit(reference).scheme or reference.startswith(("/", "\\"))
            or "\\" in reference or "?" in reference or "#" in reference):
        return None
    parts = reference.split("/")
    if any(part in ("", ".", "..") for part in parts):
        return None
    if not reference.startswith("resources/images/") or not reference.endswith(".png"):
        return None
    path = package.joinpath(*parts)
    if not path.is_file() or path.is_symlink() or not path.resolve().is_relative_to(package.resolve()):
        return None
    if path.stat().st_size > 512 * 1024:
        return None
    with path.open("rb") as image:
        header = image.read(24)
    if len(header) != 24 or header[:16] != b"\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR":
        return None
    width, height = struct.unpack(">II", header[16:24])
    if not (0 < width <= 1024 and 0 < height <= 1024):
        return None
    return path


class PackageImagesTest(unittest.TestCase):
    def test_first_party_package_marks_are_bundled_and_versioned(self):
        for package_name, (package_id, version) in IMAGE_PACKAGES.items():
            with self.subTest(package=package_name):
                package = ROOT / package_name
                kind = "module" if package_name.startswith("modules/") else "extension"
                manifest = json.loads((package / f"{kind}.json").read_text(encoding="utf-8"))
                self.assertEqual(manifest["id"], package_id)
                self.assertEqual(manifest["version"], version)
                self.assertEqual(manifest["logo"], "resources/images/logo.png")
                self.assertIsNotNone(image_path(package, manifest["logo"]))

    def test_every_declared_image_is_local_and_safe(self):
        manifests = list((ROOT / "modules").glob("*/module.json"))
        manifests += list((ROOT / "extensions").glob("*/*/extension.json"))
        self.assertGreaterEqual(len(manifests), len(IMAGE_PACKAGES))
        for manifest_file in manifests:
            with self.subTest(manifest=str(manifest_file)):
                manifest = json.loads(manifest_file.read_text(encoding="utf-8"))
                if "logo" in manifest:
                    self.assertIsNotNone(image_path(manifest_file.parent, manifest["logo"]))

    def test_missing_and_unsafe_images_have_no_usable_image(self):
        package = ROOT / "extensions/payments/stripe"
        manifest = json.loads((package / "extension.json").read_text(encoding="utf-8"))
        self.assertNotIn("logo", manifest)
        for reference in (None, "", "https://example.test/logo.png", "//host/logo.png",
                          "../logo.png", "resources/images/../../extension.json",
                          "resources\\images\\logo.png", "resources/images/missing.png",
                          "resources/images/logo.svg", "data:image/png;base64,AA=="):
            with self.subTest(reference=reference):
                self.assertIsNone(image_path(package, reference))


if __name__ == "__main__":
    unittest.main()
