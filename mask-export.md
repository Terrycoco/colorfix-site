# Photoshop mask export (group with layer mask)

1. **Enter the group**
   - Uncollapse the group in the Layers panel so you see the actual pixel layers.
   - If every layer inside is clipped/masked, select the group and press `Ctrl/Cmd+Shift+C` (`Edit ▸ Copy Merged`) to copy the visible result.

2. **Paste onto a new transparent layer**
   - Create a new document at the target size (e.g. 1600×1597).
   - Paste (`Ctrl/Cmd+V`). This snapshot is now a normal pixel layer with transparency.

3. **Clean up the mask**
   - Make sure only the mask pixels are visible (background hidden → checkerboard shows).

4. **Export**
   - `File ▸ Export ▸ Export As…`
   - Format: PNG, Transparency ON, Width 1600 px (height auto ≈ 1597).
   - Export → `body.png`, `trim.png`, etc.

This works even when the original mask lives on a group: copy‑merged produces a raster layer you can export as a proper transparent PNG.
