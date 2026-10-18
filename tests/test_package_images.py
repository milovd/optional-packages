"""Package-owned, optional image metadata contract (no Core runtime code here)."""

import json
import struct
import unittest
from pathlib import Path
from urllib.parse import urlsplit


ROOT = Path(__file__).resolve().parents[1]
IMAGE_PACKAGES = {
    "modules/digital-delivery": ("digital-delivery", "1.1.2"),
    "modules/domains": ("domains", "1.1.2"),
    "modules/downloads": ("downloads", "1.1.2"),
    "modules/events": ("events", "1.1.2"),
    "modules/provisioning": ("provisioning", "1.1.2"),
    "extensions/domains/cloudflare-domain": ("cloudflare-domain", "1.0.2"),
    "extensions/domains/namecheap-domain": ("namecheap-domain", "1.0.2"),
    "extensions/payments/mollie": ("mollie", "1.0.2"),
    "extensions/payments/paddle": ("paddle", "1.0.2"),
    "extensions/payments/paypal": ("paypal", "1.0.2"),
    "extensions/payments/stripe": ("stripe", "1.0.2"),
    "extensions/payments/tebex": ("tebex", "1.0.2"),
    "extensions/shipping/postnl": ("postnl", "1.0.2"),
    "extensions/provisioning/convoy": ("convoy", "1.0.2"),
    "extensions/provisioning/cpanel": ("cpanel", "1.0.2"),
    "extensions/provisioning/directadmin": ("directadmin", "1.0.2"),
    "extensions/provisioning/enhance": ("enhance", "1.0.2"),
    "extensions/provisioning/plesk": ("plesk", "1.0.2"),
    "extensions/provisioning/proxmox": ("proxmox", "1.0.2"),
    "extensions/provisioning/pterodactyl": ("pterodactyl", "1.0.3"),
    "extensions/provisioning/virtfusion": ("virtfusion", "1.0.2"),
    "extensions/provisioning/virtualizor": ("virtualizor", "1.0.2"),
}
PHOTO_PACKAGES = {
    "modules/digital-delivery": ("https://commons.wikimedia.org/wiki/File:U2F_Hardware_Authentication_Security_Keys_(Yubico_Yubikey_4_and_Feitian_MultiPass_FIDO)_(42286852310).jpg", "Tony Webster", "https://creativecommons.org/licenses/by/2.0/"),
    "modules/domains": ("https://commons.wikimedia.org/wiki/File:19-inch_rackmount_Ethernet_switches_and_patch_panels.jpg", "Dsimic", "https://creativecommons.org/licenses/by-sa/4.0/"),
    "modules/downloads": ("https://commons.wikimedia.org/wiki/File:Aerial_view_of_woman_using_computer_laptop_and_a_smartphone_on_wooden_table_(40640189245).jpg", "Rawpixel Ltd", "https://creativecommons.org/licenses/by/2.0/"),
    "modules/events": ("https://commons.wikimedia.org/wiki/File:Rows_of_empty_theatre_seats_(29592010815).jpg", "Thomas Quine", "https://creativecommons.org/licenses/by/2.0/"),
    "modules/provisioning": ("https://commons.wikimedia.org/wiki/File:Datacenter_Server_Racks_(22370909788).jpg", "Carl Lender", "https://creativecommons.org/licenses/by/2.0/"),
    "extensions/domains/cloudflare-domain": ("https://commons.wikimedia.org/wiki/File:Under_Floor_Cable_Runs_Str_2.jpg", "Robert.Harker", "https://creativecommons.org/licenses/by-sa/3.0/"),
    "extensions/domains/namecheap-domain": ("https://commons.wikimedia.org/wiki/File:Under_Floor_Cable_Runs_Rack.jpg", "Robert.Harker", "https://creativecommons.org/licenses/by-sa/3.0/"),
    "extensions/payments/mollie": ("https://commons.wikimedia.org/wiki/File:Mobile_payment_02.JPG", "HLundgaard", "https://creativecommons.org/licenses/by-sa/3.0/"),
    "extensions/payments/paddle": ("https://commons.wikimedia.org/wiki/File:Mobile_payment_03.JPG", "HLundgaard", "https://creativecommons.org/licenses/by-sa/3.0/"),
    "extensions/payments/paypal": ("https://commons.wikimedia.org/wiki/File:POS_terminal_at_a_bookstore.jpg", "Scott Brody", "https://creativecommons.org/licenses/by-sa/4.0/"),
    "extensions/payments/stripe": ("https://commons.wikimedia.org/wiki/File:Payment_terminal_at_self-checkout.jpg", "Anselm Schüler", "https://creativecommons.org/licenses/by-sa/4.0/"),
    "extensions/payments/tebex": ("https://commons.wikimedia.org/wiki/File:Sumup_Solo_P1110479.jpg", "-stk", "https://creativecommons.org/licenses/by-sa/4.0/"),
    "extensions/shipping/postnl": ("https://commons.wikimedia.org/wiki/File:Handing_a_package_over_for_delivery.jpg", "Meanwell Packaging", "https://creativecommons.org/licenses/by/2.0/"),
    "extensions/provisioning/convoy": ("https://commons.wikimedia.org/wiki/File:NOIRLab_HQ_Server_Racks_(6V6A0375-CC).jpg", "NOIRLab/NSF/AURA/T. Slovinský", "https://creativecommons.org/licenses/by/4.0/"),
    "extensions/provisioning/cpanel": ("https://commons.wikimedia.org/wiki/File:BalticServers_data_center.jpg", "BalticServers.com", "https://creativecommons.org/licenses/by-sa/3.0/"),
    "extensions/provisioning/directadmin": ("https://commons.wikimedia.org/wiki/File:CERN_Server_03.jpg", "Florian Hirzinger", "https://creativecommons.org/licenses/by-sa/3.0/"),
    "extensions/provisioning/enhance": ("https://commons.wikimedia.org/wiki/File:NOIRLab_HQ_Server_Racks_(6V6A0395-CC).jpg", "NOIRLab/NSF/AURA/T. Slovinský", "https://creativecommons.org/licenses/by/4.0/"),
    "extensions/provisioning/plesk": ("https://commons.wikimedia.org/wiki/File:Front_of_server_racks_at_NERSC.jpg", "Derrick Coetzee", "https://creativecommons.org/publicdomain/zero/1.0/"),
    "extensions/provisioning/proxmox": ("https://commons.wikimedia.org/wiki/File:Virginia_Tech_-_data_center.jpg", "Christopher Bowns", "https://creativecommons.org/licenses/by-sa/2.0/"),
    "extensions/provisioning/pterodactyl": ("https://commons.wikimedia.org/wiki/File:Network-Engineering_Ashlan_Chidester_8.jpg", "Cloud899", "https://creativecommons.org/licenses/by-sa/4.0/"),
    "extensions/provisioning/virtfusion": ("https://commons.wikimedia.org/wiki/File:Network_cables_in_server_room.jpg", "ProjectManhattan", "https://creativecommons.org/licenses/by-sa/3.0/"),
    "extensions/provisioning/virtualizor": ("https://commons.wikimedia.org/wiki/File:Compaq_server_racks,_HP_server_towers_-_IMG_0143.jpg", "Jemimus", "https://creativecommons.org/licenses/by/2.0/"),
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
        self.assertEqual(
            {manifest_file.parent.relative_to(ROOT).as_posix() for manifest_file in manifests},
            set(IMAGE_PACKAGES),
        )
        self.assertEqual(set(IMAGE_PACKAGES), set(PHOTO_PACKAGES))
        for manifest_file in manifests:
            with self.subTest(manifest=str(manifest_file)):
                manifest = json.loads(manifest_file.read_text(encoding="utf-8"))
                if "logo" in manifest:
                    self.assertIsNotNone(image_path(manifest_file.parent, manifest["logo"]))

    def test_photo_packages_keep_individual_credits_with_the_image(self):
        for package_name, (source, creator, license_url) in PHOTO_PACKAGES.items():
            with self.subTest(package=package_name):
                package = ROOT / package_name
                photo = package / "resources/images/logo.png"
                self.assertEqual(image_path(package, "resources/images/logo.png"), photo)
                with photo.open("rb") as image:
                    image.seek(16)
                    self.assertEqual(struct.unpack(">II", image.read(8)), (320, 400))
                notice = (package / "resources/images/ATTRIBUTION.md").read_text(encoding="utf-8")
                self.assertIn("logo.png", notice)
                self.assertIn(source, notice)
                self.assertIn(creator, notice)
                self.assertIn(license_url, notice)

    def test_missing_and_unsafe_images_have_no_usable_image(self):
        package = ROOT / "extensions/payments/stripe"
        for reference in (None, "", "https://example.test/logo.png", "//host/logo.png",
                          "../logo.png", "resources/images/../../extension.json",
                          "resources\\images\\logo.png", "resources/images/missing.png",
                          "resources/images/logo.svg", "data:image/png;base64,AA=="):
            with self.subTest(reference=reference):
                self.assertIsNone(image_path(package, reference))


if __name__ == "__main__":
    unittest.main()
