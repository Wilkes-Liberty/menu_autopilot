---
section: Changed
---
`NavStatusReporter::getData()` reads the `menu_autopilot` map field itself. The leftover `_menu_autopilot_link_data()` wrapper is gone. Empty-field and non-array maps still return `[]`.
