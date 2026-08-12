import RexManagementDialog from "@components/REX/RexManagementDialog";

export default function RexManagementDialogTestPage() {
  return (
    <RexManagementDialog
      open={true}
      title="Mojdeh Test Project"
      onClose={() => {}}
    />
  );
}
