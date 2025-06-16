import { draftMode } from "next/headers"
import { notFound } from "next/navigation"
import { getDraftData } from "next-drupal/draft"
import { Article } from "@/components/drupal/Article"
import { BasicPage } from "@/components/drupal/BasicPage"
import { drupal } from "@/lib/drupal"
import type { Metadata, ResolvingMetadata } from "next"
import type { DrupalNode, JsonApiParams } from "next-drupal"
import { DrupalJsonApiParams } from "drupal-jsonapi-params"

async function getNode(slug: string[]) {
  const path = `/${slug.join("/")}` // e.g. “/about”
  const draftData = await getDraftData() // { path, resourceVersion? }

  // Build the query string with the helper.
  const apiParams = new DrupalJsonApiParams()

  // 1. Include relationships only when we need them.
  //    Equivalent to: ?include=field_image,uid
  apiParams.addInclude(["field_image", "uid"])

  // 2. If we are previewing this exact path, request the working-copy revision.
  if (draftData?.path === path) {
    apiParams.addCustomParam({
      resourceVersion: draftData.resourceVersion ?? "rel:working-copy",
    })
  }

  // 3. Translate the path to discover type + UUID.
  const translated = await drupal.translatePath(path)
  if (!translated) throw new Error("Resource not found", { cause: "NotFound" })

  const type = translated.jsonapi!.resourceName!
  const uuid = translated.entity.uuid

  console.log("👇👇===== apiParams =====👇👇")
  console.log(apiParams.getQueryObject())
  console.log("👆👆===== apiParams =====👆👆")

  const params = apiParams.getQueryObject()

  // 4. Fetch the node with the generated params object.
  const resource = await drupal.getResource<DrupalNode>(type, uuid, {
    params: params, // ← helper returns the object
  })

  console.log("👇👇===== resource =====👇👇")
  console.log(resource)
  console.log("👆👆===== resource =====👆👆")

  if (!resource) {
    throw new Error(
      `Failed to fetch resource: ${translated.jsonapi?.individual}`,
      { cause: "DrupalError" }
    )
  }

  return resource
}

type NodePageParams = {
  slug: string[]
}
type NodePageProps = {
  params: Promise<NodePageParams>
  searchParams: Promise<{ [key: string]: string | string[] | undefined }>
}

export async function generateMetadata(
  props: NodePageProps,
  parent: ResolvingMetadata
): Promise<Metadata> {
  const params = await props.params

  const { slug } = params

  let node
  try {
    node = await getNode(slug)
  } catch (e) {
    // If we fail to fetch the node, don't return any metadata.
    return {}
  }

  return {
    title: node.title,
  }
}

const RESOURCE_TYPES = ["node--page", "node--article"]

export async function generateStaticParams(): Promise<NodePageParams[]> {
  const resources = await drupal.getResourceCollectionPathSegments(
    RESOURCE_TYPES,
    {
      // The pathPrefix will be removed from the returned path segments array.
      // pathPrefix: "/blog",
      // The list of locales to return.
      // locales: ["en", "es"],
      // The default locale.
      // defaultLocale: "en",
    }
  )

  return resources.map((resource) => {
    // resources is an array containing objects like: {
    //   path: "/blog/some-category/a-blog-post",
    //   type: "node--article",
    //   locale: "en", // or `undefined` if no `locales` requested.
    //   segments: ["blog", "some-category", "a-blog-post"],
    // }
    return {
      slug: resource.segments,
    }
  })
}

export default async function NodePage(props: NodePageProps) {
  const params = await props.params

  const { slug } = params

  const draft = await draftMode()
  const isDraftMode = draft.isEnabled

  let node
  try {
    node = await getNode(slug)
  } catch (error) {
    // If getNode throws an error, tell Next.js the path is 404.
    notFound()
  }

  // If we're not in draft mode and the resource is not published, return a 404.
  if (!isDraftMode && node?.status === false) {
    notFound()
  }

  return (
    <>
      {node.type === "node--page" && <BasicPage node={node} />}
      {node.type === "node--article" && <Article node={node} />}
    </>
  )
}
