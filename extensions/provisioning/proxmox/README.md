# Proxmox VE provisioning extension

Original Agovena provisioning extension for Proxmox VE. Supports:

- Full clone from a template VMID
- Lifecycle: provision, suspend, unsuspend, terminate, sync
- Customer actions: start, stop, reboot
- API token auth via extension/server settings (encrypted secrets)
- Task polling for long-running clone/delete operations

This extension intentionally focuses on core VM lifecycle. IP pools, OS catalogs, backups UI, and bandwidth metering are roadmap items compared to full hosting panels.
