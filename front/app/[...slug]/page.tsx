/* ------------------------------------------------------------------ */
/*  app/[...slug]/page.tsx — pure SSR (Next.js 15)                    */
/* ------------------------------------------------------------------ */
import { draftMode, headers } from "next/headers"
import { notFound } from "next/navigation"
import { getDraftData } from "next-drupal/draft"
import { DrupalJsonApiParams } from "drupal-jsonapi-params"
import { drupal } from "@/lib/drupal"

import type { DrupalNode } from "next-drupal"
import { Article } from "@/components/drupal/Article"
import { BasicPage } from "@/components/drupal/BasicPage"

/* ───────────── helper ────────────────────────────────────────────── */
async function requestIsIframe(): Promise<boolean> {
  const hdrs = await headers() // Next 15: async
  const dest = hdrs.get("sec-fetch-dest")
  return dest === "iframe" || dest === "frame"
}

/* ───────────── fetch node ────────────────────────────────────────── */
async function getNode(slug: string[]): Promise<DrupalNode> {
  const path = `/${slug.join("/")}`
  const { isEnabled } = await draftMode() // preview cookie?
  const draftData = await getDraftData() // { path, resourceVersion }
  const inIframe = await requestIsIframe() // header test

  /* 1 — resolve alias → type & UUID (auth needed for unpublished) */
  const translated = await drupal.translatePath(path, { withAuth: true })
  if (!translated) throw new Error("NotFound")

  const type = translated.jsonapi!.resourceName!
  const uuid = translated.entity.uuid

  /* 2 — build query params */
  const params = new DrupalJsonApiParams().addInclude(
    type === "node--article" ? ["field_image", "uid"] : []
  )

  const isIframePreview =
    inIframe &&
    isEnabled &&
    draftData?.path === path &&
    draftData?.resourceVersion

  if (isIframePreview) {
    // rel:working-copy  *or*  rel:latest-version
    params.addCustomParam({ resourceVersion: draftData.resourceVersion })
  } else {
    // public (or cookie in top-level tab) → only published
    params.addFilter("status", "1")
  }

  /* 3 — fetch the node */
  const node = await drupal.getResource<DrupalNode>(type, uuid, {
    params: params.getQueryObject(),
    withAuth: true, // needed for drafts
  })
  if (!node) throw new Error("DrupalError")
  return node
}

/* ───────────── page component ────────────────────────────────────── */
export default async function NodePage({
  params,
}: {
  params: Promise<{ slug: string[] }> // Next 15: Promise
}) {
  const { slug } = await params // resolve the promise

  let node: DrupalNode
  try {
    node = await getNode(slug) // may throw → 404
  } catch {
    notFound()
  }

  /* guard — public visit must not show unpublished nodes */
  const inIframe = await requestIsIframe()
  if (!inIframe && node.status === false) notFound()

  return (
    <>
      {node.type === "node--page" && <BasicPage node={node} />}
      {node.type === "node--article" && <Article node={node} />}
    </>
  )
}
