"use client"

import { useEffect, useState } from "react"
import { useRouter } from "next/navigation"

export function DraftAlertClient({
  isDraftEnabled,
}: {
  isDraftEnabled: boolean
}) {
  const [show, setShow] = useState(false)
  const router = useRouter()

  /* Show banner only in the top-level window (not inside the Drupal iframe) */
  useEffect(() => {
    setShow(isDraftEnabled && window.top === window.self)
  }, [isDraftEnabled])

  if (!show) return null

  async function exitDraft() {
    /* 1 · Clear the preview cookies on the server */
    await fetch("/api/disable-draft", { credentials: "include" })

    /* 2 · Navigate to the site’s top page */
    router.replace("/") // or router.push("/") if you prefer history

    /* 3 · Force a fresh server render on that new route */
    router.refresh()
  }

  return (
    <div className="sticky top-0 left-0 z-50 w-full px-2 py-1 text-center text-white bg-black">
      This page is a draft.
      <button
        onClick={exitDraft}
        className="ml-3 rounded border px-1.5 hover:bg-white hover:text-black"
      >
        Exit draft mode
      </button>
    </div>
  )
}
