# REX Fetch UI

Place both folders under `src/components/REX/`.

Host example:

```jsx
<FetchRexButton
  request={{
    label: "Exterior Makeovers Vol 1 — Public",
    resolverKey: "playlist_experience",
    resourceType: "playlist",
    resourceId: 12,
    context: { experience_key: "public" },
    alias: "exterior-transform",
    adminNote: "Migrated from PI #22 — old QR code",
  }}
  onCreated={({ reservationId, token }) => {
    // Host decides what to do with the returned REX identity.
  }}
/>
```

Flow:
1. Fetch REX opens the review dialog.
2. User may edit all proposed values.
3. Preview calls `/v2/admin/rex/preview.php`.
4. Get Token remains disabled until the resolver preview is valid and current.
5. Get Token calls `/v2/admin/rex/create.php`.
6. If alias is present, it calls `/v2/admin/rex/create-alias.php`.
7. Host receives `reservationId` + `token`.
