/* ------------------------------------------------------------------ */
/*  app/[...slug]/page.tsx ─ pure SSR                                 */
/* ------------------------------------------------------------------ */
import { draftMode, headers } from "next/headers"
import { notFound } from "next/navigation"
import { getDraftData } from "next-drupal/draft"
import { Article } from "@/components/drupal/Article"
import { BasicPage } from "@/components/drupal/BasicPage"
import { drupal } from "@/lib/drupal"
import type { DrupalNode } from "next-drupal"
import { DrupalJsonApiParams } from "drupal-jsonapi-params"
import { DraftAlertServer } from "@/components/misc/DraftAlert/Server"

/** true when this HTTP request is inside an <iframe> (Drupal preview) */
async function requestIsIframe(): Promise<boolean> {
  const hdrs = await headers()
  const dest = hdrs.get("sec-fetch-dest")
  return dest === "iframe" || dest === "frame"
}

/** fetches either the published node or, inside the preview iframe, the draft */
async function getNode(slug: string[]): Promise<DrupalNode> {
  const path = `/${slug.join("/")}`
  const { isEnabled: isDraftMode } = await draftMode() // preview cookie
  const draftData = await getDraftData() // { path, resourceVersion }
  const inIframe = await requestIsIframe()

  /* 1. Resolve alias → type + UUID (needs auth for unpublished paths) */
  const translated = await drupal.translatePath(path, { withAuth: true })
  if (!translated) throw new Error("NotFound")

  const type = translated.jsonapi!.resourceName!
  const uuid = translated.entity.uuid

  /* 2. Build query parameters */
  const params = new DrupalJsonApiParams().addInclude(
    type === "node--article" ? ["field_image", "uid"] : []
  )

  const showWorkingCopy = inIframe && isDraftMode && draftData?.path === path

  if (showWorkingCopy) {
    params.addCustomParam({
      resourceVersion: draftData.resourceVersion ?? "rel:working-copy",
    })
  } else {
    params.addFilter("status", "1") // published only
  }

  /* 3. Fetch the node */
  const node = await drupal.getResource<DrupalNode>(type, uuid, {
    params: params.getQueryObject(),
  })
  if (!node) throw new Error("DrupalError")
  return node
}

/* ------------------------------------------------------------------ */
/*  Page component (SSR)                                              */
/* ------------------------------------------------------------------ */
export default async function NodePage({
  params,
}: {
  params: { slug: string[] }
}) {
  let node: DrupalNode
  try {
    node = await getNode(params.slug) // may throw → 404
  } catch {
    notFound()
  }

  const inIframe = await requestIsIframe()
  if (!inIframe && node.status === false) {
    // Public request hit an unpublished node → 404
    notFound()
  }

  return (
    <>
      {node.type === "node--page" && <BasicPage node={node} />}
      {node.type === "node--article" && <Article node={node} />}
    </>
  )
}
