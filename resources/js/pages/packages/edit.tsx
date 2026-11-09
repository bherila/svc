// Placeholder: the owner/admin package editor is built in slice 1b against these props.
type Props = { package: { link_name: string } | null };

export default function PackageEdit({ package: pkg }: Props) {
    return <p>{pkg?.link_name ?? 'New package'}</p>;
}
