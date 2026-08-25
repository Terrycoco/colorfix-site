import fs from "node:fs";
import path from "node:path";
import { spawn } from "node:child_process";
import { fileURLToPath } from "node:url";

const ROOT = process.cwd();


function readArg(name) {
  const prefix = `--${name}=`;

  const match =
    process.argv.find(
      (arg) =>
        arg.startsWith(prefix)
    );

  return match
    ? match.slice(prefix.length)
    : "";
}


function ensureDir(dir) {
  fs.mkdirSync(
    dir,
    {
      recursive: true,
    }
  );
}


function readRecipe(recipePath) {
  if (!recipePath) {
    throw new Error(
      "--recipe is required."
    );
  }

  if (!fs.existsSync(recipePath)) {
    throw new Error(
      `Remotion recipe not found: ${recipePath}`
    );
  }

  const raw =
    fs.readFileSync(
      recipePath,
      "utf8"
    );

  const recipe =
    JSON.parse(raw);


  if (
    !recipe
    ||
    typeof recipe !== "object"
    ||
    Array.isArray(recipe)
  ) {
    throw new Error(
      `Invalid Remotion recipe: ${recipePath}`
    );
  }


  const compositionId =
    String(
      recipe.composition_id || ""
    ).trim();

  if (!compositionId) {
    throw new Error(
      "Remotion recipe requires composition_id."
    );
  }


  if (
    !recipe.plan
    ||
    typeof recipe.plan !== "object"
    ||
    Array.isArray(recipe.plan)
  ) {
    throw new Error(
      "Remotion recipe requires plan."
    );
  }


  if (
    !recipe.render
    ||
    typeof recipe.render !== "object"
    ||
    Array.isArray(recipe.render)
  ) {
    throw new Error(
      "Remotion recipe requires render instructions."
    );
  }


  const codec =
    String(
      recipe.render.codec || ""
    ).trim();

  if (!codec) {
    throw new Error(
      "Remotion recipe requires Creator-owned render.codec."
    );
  }


  return {
    compositionId,
    codec,
  };
}


function run(
  command,
  args
) {
  return new Promise(
    (resolve, reject) => {
      const child =
        spawn(
          command,
          args,
          {
            cwd:
              ROOT,

            stdio:
              "inherit",

            env:
              process.env,
          }
        );

      child.on(
        "error",
        reject
      );

      child.on(
        "exit",
        (code) => {
          if (code === 0) {
            resolve();
            return;
          }

          reject(
            new Error(
              `${command} exited with code ${code}`
            )
          );
        }
      );
    }
  );
}


/**
 * GENERIC REMOTION EXECUTOR
 *
 * This is the oven switch.
 *
 * It knows only how to:
 * - read the Creator's recipe
 * - invoke the requested Remotion composition
 * - use the requested codec
 * - write the finished file
 *
 * It makes no Pinterest, YouTube, BAV, timing, layout,
 * composition, or codec decisions.
 */
export async function renderRemotionVideo({
  recipePath,
  outputPath,
}) {
  if (!outputPath) {
    throw new Error(
      "outputPath is required."
    );
  }


  const {
    compositionId,
    codec,
  } =
    readRecipe(
      recipePath
    );


  ensureDir(
    path.dirname(
      outputPath
    )
  );


  await run(
    "npx",
    [
      "remotion",
      "render",
      "src/remotion/index.jsx",
      compositionId,
      outputPath,
      `--props=${recipePath}`,
      "--overwrite",
      `--codec=${codec}`,
    ]
  );


  const stat =
    fs.statSync(
      outputPath
    );

  if (
    !stat.isFile()
    ||
    stat.size <= 0
  ) {
    throw new Error(
      "Remotion finished without producing a valid output file."
    );
  }


  return {
    composition_id:
      compositionId,

    codec,

    output_path:
      outputPath,

    file_size_bytes:
      stat.size,
  };
}


async function main() {
  const recipeArg =
    readArg("recipe");

  const outputArg =
    readArg("output");


  if (!recipeArg) {
    throw new Error(
      "--recipe is required."
    );
  }

  if (!outputArg) {
    throw new Error(
      "--output is required."
    );
  }


  const recipePath =
    path.resolve(
      ROOT,
      recipeArg
    );

  const outputPath =
    path.resolve(
      ROOT,
      outputArg
    );


  const result =
    await renderRemotionVideo({
      recipePath,
      outputPath,
    });


  console.log(
    `Rendered Remotion composition: ${result.composition_id}`
  );

  console.log(
    result.output_path
  );
}


const invokedDirectly =
  process.argv[1]
  &&
  path.resolve(
    process.argv[1]
  )
  ===
  path.resolve(
    fileURLToPath(
      import.meta.url
    )
  );


if (invokedDirectly) {
  main()
    .catch(
      (error) => {
        console.error(
          error?.message ||
          error
        );

        process.exit(1);
      }
    );
}