/* ------------------------------------------------------------------ */
/*  app/[...slug]/page.tsx — pure SSR (Next.js 15)                    */
/* ------------------------------------------------------------------ */
import { draftMode } from "next/headers"
import { notFound } from "next/navigation"
import { getDraftData } from "next-drupal/draft"
import { DrupalJsonApiParams } from "drupal-jsonapi-params"
import { drupal } from "@/lib/drupal"

import type { DrupalNode } from "next-drupal"
import { Article } from "@/components/drupal/Article"
import { BasicPage } from "@/components/drupal/BasicPage"

/* ───────────────── helper ────────────────────────────────────────── */
async function getNode(slug: string[]): Promise<DrupalNode> {
  const path = `/${slug.join("/")}`
  const { isEnabled } = await draftMode() // preview cookie?
  const draftData = await getDraftData() // { path, resourceVersion }

  /* 1 - Resolve alias → type + UUID (needs auth for unpublished paths) */
  const translated = await drupal.translatePath(path, { withAuth: true })
  if (!translated) throw new Error("NotFound")

  const type = translated.jsonapi!.resourceName!
  const uuid = translated.entity.uuid

  /* 2 - Build query parameters */
  const params = new DrupalJsonApiParams().addInclude(
    type === "node--article" ? ["field_image", "uid"] : []
  )

  const isPreviewRequest = isEnabled && draftData?.path === path

  if (isPreviewRequest && draftData?.resourceVersion) {
    // Forward exactly what Drupal asked for: working-copy OR latest-version
    params.addCustomParam({ resourceVersion: draftData.resourceVersion })
  } else {
    // Public request → only published revision
    params.addFilter("status", "1")
  }

  /* 3 - Fetch the node */
  const node = await drupal.getResource<DrupalNode>(type, uuid, {
    params: params.getQueryObject(),
    withAuth: true, // needs auth for unpublished nodes
  })
  if (!node) throw new Error("DrupalError")

  return node
}

/* ───────────────── page component ────────────────────────────────── */
export default async function NodePage({
  params,
}: {
  params: Promise<{ slug: string[] }> // Next 15 hands this in as a Promise
}) {
  const { slug } = await params // resolve the promise

  let node: DrupalNode
  try {
    node = await getNode(slug) // throws → 404
  } catch {
    notFound()
  }

  /* Guard: public visit must never see an unpublished node               */
  /* (When draftMode cookie is set we skip this check, so if a preview    */
  /*  somehow requests “working-copy” directly in a new tab it still      */
  /*  works.)                                                             */
  const { isEnabled } = await draftMode()
  if (!isEnabled && node.status === false) notFound()

  return (
    <>
      {node.type === "node--page" && <BasicPage node={node} />}
      {node.type === "node--article" && <Article node={node} />}
    </>
  )
}
