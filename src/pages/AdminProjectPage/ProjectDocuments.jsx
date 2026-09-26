import {

  useEffect,

  useMemo,

  useState,

} from "react";



import {

  AdminEmptyState,

  AdminNotice,

  AdminObjectList,

  AdminObjectListItem,

  AdminPanel,

  AdminStack,

} from "@components/AdminLayout";



import {

  API_FOLDER,

} from "@helpers/config";





const LIST_URL =

  `${API_FOLDER}/v2/admin/projects/documents/list.php`;



const GENERATE_URL =

  `${API_FOLDER}/v2/admin/projects/documents/generate.php`;





function documentMeta(document) {

  const meta = [];



  if (document?.status) {

    meta.push(

      String(

        document.status

      )

    );

  }



  if (document?.template_key) {

    meta.push(

      String(

        document.template_key

      )

    );

  }



  return meta;

}





export default function ProjectDocuments({

  projectId,

  onSelectedDocumentChange,

}) {

  const [

    documents,

    setDocuments,

  ] = useState([]);



  const [

    selectedDocumentId,

    setSelectedDocumentId,

  ] = useState(null);



  const [

    loading,

    setLoading,

  ] = useState(true);



  const [

    generating,

    setGenerating,

  ] = useState(false);



  const [

    error,

    setError,

  ] = useState("");



  const [

    statusMessage,

    setStatusMessage,

  ] = useState("");





  useEffect(() => {

    let active = true;



    async function loadDocuments() {

      setLoading(true);

      setError("");

      setStatusMessage("");



      try {

        const res =

          await fetch(

            `${LIST_URL}?project_id=${encodeURIComponent(

              Number(projectId)

            )}&_=${Date.now()}`,

            {

              credentials:

                "include",

            }

          );



        const text =

          await res.text();



        let data = null;



        try {

          data =

            JSON.parse(

              text

            );

        } catch {

          throw new Error(

            `HTTP ${res.status}: ${text.slice(0, 250)}`

          );

        }



        if (

          !res.ok

          ||

          !data?.ok

        ) {

          throw new Error(

            data?.error ||

            "Failed to load Project Documents."

          );

        }



        if (!active) {

          return;

        }



        const rows =

          Array.isArray(

            data.documents

          )

            ? data.documents

            : [];



        setDocuments(

          rows

        );



        setSelectedDocumentId(

          (current) => {

            const currentStillExists =

              current

              &&

              rows.some(

                (document) =>

                  Number(

                    document.id

                  ) ===

                  Number(

                    current

                  )

              );



            if (currentStillExists) {

              return current;

            }



            return rows[0]?.id

              ? Number(

                  rows[0].id

                )

              : null;

          }

        );



      } catch (err) {

        if (active) {

          setDocuments([]);

          setSelectedDocumentId(null);

          setError(

            err?.message ||

            "Failed to load Project Documents."

          );

        }



      } finally {

        if (active) {

          setLoading(false);

        }

      }

    }



    if (

      Number(projectId || 0) > 0

    ) {

      loadDocuments();

    } else {

      setDocuments([]);

      setSelectedDocumentId(null);

      setLoading(false);

    }



    return () => {

      active = false;

    };

  }, [

    projectId,

  ]);





  const selectedDocument =

    useMemo(

      () =>

        documents.find(

          (document) =>

            Number(

              document.id

            ) ===

            Number(

              selectedDocumentId

            )

        )

        || null,

      [

        documents,

        selectedDocumentId,

      ]

    );



  useEffect(

    () => {

      onSelectedDocumentChange?.(

        selectedDocument

      );

    },

    [

      onSelectedDocumentChange,

      selectedDocument,

    ]

  );



  useEffect(

    () =>

      () => {

        onSelectedDocumentChange?.(

          null

        );

      },

    [

      onSelectedDocumentChange,

    ]

  );





  async function generateAgreement(

    event

  ) {

    event.preventDefault();



    if (

      generating

      ||

      Number(projectId || 0) <= 0

    ) {

      return;

    }



    setGenerating(true);

    setError("");

    setStatusMessage("");



    try {

      const res =

        await fetch(

          GENERATE_URL,

          {

            method:

              "POST",



            credentials:

              "include",



            headers: {

              "Content-Type":

                "application/json",

            },



            body:

              JSON.stringify({

                project_id:

                  Number(

                    projectId

                  ),



                template_key:

                  "scope_agreement",

              }),

          }

        );



      const text =

        await res.text();



      let data = null;



      try {

        data =

          JSON.parse(

            text

          );

      } catch {

        throw new Error(

          `HTTP ${res.status}: ${text.slice(0, 250)}`

        );

      }



      if (

        !res.ok

        ||

        !data?.ok

        ||

        !data?.document

      ) {

        throw new Error(

          data?.error ||

          "Failed to generate agreement."

        );

      }



      const generated =

        data.document;



      setDocuments(

        (current) => {

          const withoutGenerated =

            current.filter(

              (document) =>

                Number(

                  document.id

                ) !==

                Number(

                  generated.id

                )

            );



          return [

            generated,

            ...withoutGenerated,

          ];

        }

      );



      setSelectedDocumentId(

        Number(

          generated.id

        )

      );



      setStatusMessage(

        generated.status === "draft"

          ? "Agreement draft generated."

          : "Agreement generated."

      );



    } catch (err) {

      setError(

        err?.message ||

        "Failed to generate agreement."

      );



    } finally {

      setGenerating(false);

    }

  }





  return (

    <>

      <form

        id="admin-project-documents-generate-form"

        onSubmit={generateAgreement}

      />



      <AdminStack>

        {

          error

            ? (

                <AdminNotice variant="danger">

                  {error}

                </AdminNotice>

              )

            : null

        }



        {

          statusMessage

            ? (

                <AdminNotice variant="success">

                  {statusMessage}

                </AdminNotice>

              )

            : null

        }



        {

          generating

            ? (

                <AdminNotice>

                  Generating agreement...

                </AdminNotice>

              )

            : null

        }



        {

          loading

            ? (

                <AdminNotice>

                  Loading Project Documents...

                </AdminNotice>

              )

            : null

        }



        {

          !loading

          &&

          documents.length === 0

            ? (

                <AdminEmptyState

                  title="No documents"

                  message="Generate an agreement from the saved Project Scope."

                />

              )

            : null

        }



        {

          documents.length > 0

            ? (

                <AdminPanel

                  title="Documents"

                >

                  <AdminObjectList

                    ariaLabel="Project documents"

                  >

                    {

                      documents.map(

                        (document) => (

                          <AdminObjectListItem

                            key={

                              document.id

                            }

                            id={

                              `document-${document.id}`

                            }

                            title={

                              document.title

                              ||

                              `Document #${document.id}`

                            }

                            meta={

                              documentMeta(

                                document

                              )

                            }

                            selected={

                              Number(

                                selectedDocumentId

                              ) ===

                              Number(

                                document.id

                              )

                            }

                            onSelect={() =>

                              setSelectedDocumentId(

                                Number(

                                  document.id

                                )

                              )

                            }

                          />

                        )

                      )

                    }

                  </AdminObjectList>

                </AdminPanel>

              )

            : null

        }




      </AdminStack>

    </>

  );

}
