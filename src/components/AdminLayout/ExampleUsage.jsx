import {
  AdminMasterDetail,
  AdminListPane,
  AdminDetailPane,
  AdminObjectList,
  AdminObjectListItem,
  AdminEmptyState,
} from "@components/AdminLayout";

export default function ExampleUsage({
  projects,
  selectedProject,
  selectProject,
  openRexStatus,
}) {
  return (
    <AdminMasterDetail
      storageKey="projects-admin-list-width"
      list={
        <AdminListPane title="Projects">
          <AdminObjectList ariaLabel="Projects">
            {projects.map((project) => (
              <AdminObjectListItem
                key={project.id}
                id={project.id}
                title={project.project_name}
                meta={[
                  project.property_name,
                  project.playlist_title
                    ? `Working playlist: ${project.playlist_title}`
                    : null,
                ]}
                selected={selectedProject?.id === project.id}
                status={{
                  active: Number(project.rex_count || 0) > 0,
                  count: Number(project.rex_count || 0),
                  label: `${Number(project.rex_count || 0)} active REX reservation${Number(project.rex_count || 0) === 1 ? "" : "s"}`,
                }}
                onSelect={() => selectProject(project.id)}
                onStatusClick={() => openRexStatus(project)}
              />
            ))}
          </AdminObjectList>
        </AdminListPane>
      }
      detail={
        <AdminDetailPane ariaLabel="Project detail">
          {selectedProject ? (
            <div>{/* Project-specific detail UI goes here. */}</div>
          ) : (
            <AdminEmptyState
              title="Select a project"
              message="Choose a project from the list to view its details."
            />
          )}
        </AdminDetailPane>
      }
    />
  );
}
