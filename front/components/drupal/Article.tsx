import Image from "next/image"
import { absoluteUrl, formatDate } from "@/lib/utils"
import { drupal } from "@/lib/drupal"
import type { DrupalNode } from "next-drupal"
import { draftMode } from "next/headers"

interface ArticleProps {
  node: DrupalNode
}

export async function Article({ node, ...props }: ArticleProps) {
  const { isEnabled: isDraftMode } = await draftMode()

  console.log("👇👇===== isDraftMode =====👇👇")
  console.log(isDraftMode)
  console.log("👆👆===== isDraftMode =====👆👆")

  return (
    <article {...props}>
      <h1 className="mb-4 text-6xl font-black leading-tight">{node.title}</h1>
      {node.moderation_state !== "published" && (
        <p className="mb-4 text-red-600">This is a リビジョン！！</p>
      )}
      <div className="mb-4 text-gray-600">
        {node.uid?.display_name && (
          <span>
            Posted by{" "}
            <span className="font-semibold">{node.uid?.display_name}</span>
          </span>
        )}
        <span> - {formatDate(node.created)}</span>
      </div>
      {node.field_image && (
        <figure>
          <Image
            src={absoluteUrl(node.field_image.uri.url)}
            width={768}
            height={400}
            alt={node.field_image.resourceIdObjMeta.alt || ""}
            priority
          />
          {node.field_image.resourceIdObjMeta.title && (
            <figcaption className="py-2 text-sm text-center text-gray-600">
              {node.field_image.resourceIdObjMeta.title}
            </figcaption>
          )}
        </figure>
      )}
      {node.body?.processed && (
        <div
          dangerouslySetInnerHTML={{ __html: node.body?.processed }}
          className="mt-6 font-serif text-xl leading-loose prose"
        />
      )}
    </article>
  )
}
