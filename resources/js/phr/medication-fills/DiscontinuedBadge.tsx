/** Marks a drug resolved from RxNav's NDC history because openFDA no longer lists the product. Same style as the NDC status badges. */
export function DiscontinuedBadge() {
  return <span className="inline-flex rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">Discontinued</span>
}
