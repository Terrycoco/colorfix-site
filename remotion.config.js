import path from "node:path";
import { Config } from "@remotion/cli/config";

Config.overrideWebpackConfig((currentConfiguration) => ({
  ...currentConfiguration,
  resolve: {
    ...(currentConfiguration.resolve || {}),
    alias: {
      ...(currentConfiguration.resolve?.alias || {}),
      "@": path.resolve(process.cwd(), "src"),
      "@styles": path.resolve(process.cwd(), "src/styles"),
      "@config": path.resolve(process.cwd(), "src/config"),
      "@helpers": path.resolve(process.cwd(), "src/helpers"),
      "@components": path.resolve(process.cwd(), "src/components"),
      "@context": path.resolve(process.cwd(), "src/context"),
      "@data": path.resolve(process.cwd(), "src/data"),
      "@layout": path.resolve(process.cwd(), "src/layout"),
      "@pages": path.resolve(process.cwd(), "src/pages"),
      "@hooks": path.resolve(process.cwd(), "src/hooks"),
      "@test": path.resolve(process.cwd(), "src/test"),
      "@lib": path.resolve(process.cwd(), "src/lib"),
    },
  },
}));

Config.setPublicDir(path.resolve(process.cwd(), "public"));
Config.setDelayRenderTimeoutInMilliseconds(60000);
